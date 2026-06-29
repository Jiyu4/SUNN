<?php
/**
 * IRECSTEM 2026 Backend API
 * Handles user registration, login, contact, paper submissions, and admin functions
 * All data is stored in JSON files
 */

$requestMethod = $_SERVER['REQUEST_METHOD'];

// Handle GET requests for file downloads
if ($requestMethod === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download_paper') {
    $paperId = $_GET['id'] ?? '';
    $papers = readJsonFile(PAPERS_FILE);
    $paperFound = null;
    foreach ($papers as $paper) {
        if ($paper['id'] === $paperId) {
            $paperFound = $paper;
            break;
        }
    }

    if (!$paperFound || !isset($paperFound['file_path'])) {
        http_response_code(404);
        echo 'Paper not found.';
        exit;
    }

    $filePath = $baseDir . '/uploads/' . $paperFound['file_path'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }

    $fileName = $paperFound['file_name'] ?? 'paper';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$baseDir = __DIR__ . '/data';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

// File paths for JSON data
define('USERS_FILE', $baseDir . '/users.json');
define('CONTACTS_FILE', $baseDir . '/contacts.json');
define('PAPERS_FILE', $baseDir . '/papers.json');
define('SESSIONS_FILE', $baseDir . '/sessions.json');

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Read JSON file and return decoded data
 */
function readJsonFile($path) {
    if (!file_exists($path)) {
        return [];
    }
    $content = file_get_contents($path);
    return $content ? json_decode($content, true) : [];
}

/**
 * Write data to JSON file
 */
function writeJsonFile($path, $data) {
    $result = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($result === false) {
        return false;
    }
    return true;
}

/**
 * Send JSON response and exit
 */
function respond($success, $message, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength (min 6 characters)
 */
function isValidPassword($password) {
    return strlen($password) >= 6;
}

/**
 * Sanitize input string
 */
function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate unique session token
 */
function generateToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Get current user from session token
 */
function getCurrentUser() {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$token) {
        return null;
    }

    $sessions = readJsonFile(SESSIONS_FILE);
    foreach ($sessions as $session) {
        if ($session['token'] === $token && $session['expires'] > time()) {
            $users = readJsonFile(USERS_FILE);
            foreach ($users as $user) {
                if ($user['id'] === $session['user_id']) {
                    unset($user['password']);
                    return $user;
                }
            }
        }
    }
    return null;
}

/**
 * Check if user is admin
 */
function isAdmin() {
    $user = getCurrentUser();
    return $user !== null && ($user['role'] ?? 'user') === 'admin';
}

// ============================================
// ROUTING
// ============================================

$method = $_SERVER['REQUEST_METHOD'];

// Parse input
$input = null;
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
}

// Route handling
if ($method === 'GET') {
    // Public API endpoints (no auth required)
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'stats':
            // Get public statistics
            $users = readJsonFile(USERS_FILE);
            $papers = readJsonFile(PAPERS_FILE);
            $contacts = readJsonFile(CONTACTS_FILE);

            // Count users by date
            $userCount = count($users);
            $paperCount = count($papers);

            // Count papers by track
            $trackCounts = [
                'Science' => 0,
                'Technology' => 0,
                'Education' => 0,
                'Management' => 0
            ];
            foreach ($papers as $paper) {
                $track = $paper['track'] ?? 'Science';
                if (isset($trackCounts[$track])) {
                    $trackCounts[$track]++;
                }
            }

            respond(true, 'Statistics retrieved', [
                'total_users' => $userCount,
                'total_papers' => $paperCount,
                'total_contacts' => count($contacts),
                'papers_by_track' => $trackCounts
            ]);
            break;

        case 'papers':
            // Get all papers (public view - no sensitive data)
            $papers = readJsonFile(PAPERS_FILE);
            $publicPapers = array_map(function($paper) {
                return [
                    'id' => $paper['id'],
                    'title' => $paper['title'],
                    'author' => $paper['author'],
                    'track' => $paper['track'],
                    'abstract' => $paper['abstract'],
                    'created_at' => $paper['created_at']
                ];
            }, $papers);
            respond(true, 'Papers retrieved', ['papers' => $publicPapers]);
            break;

        case 'health':
            respond(true, 'API is healthy', ['timestamp' => date('c')]);
            break;

        default:
            respond(false, 'Unsupported action', [], 400);
    }
} else if ($method === 'POST') {
    if (!$input || !isset($input['action'])) {
        respond(false, 'Invalid request. Action is required.');
    }

    $action = $input['action'];

    switch ($action) {
        // ============================================
        // USER AUTHENTICATION
        // ============================================

        case 'register':
            $name = sanitize($input['name'] ?? '');
            $email = sanitize($input['email'] ?? '');
            $organization = sanitize($input['organization'] ?? '');
            $password = $input['password'] ?? '';

            // Validation
            if ($name === '') {
                respond(false, 'Please provide your full name.');
            }
            if ($email === '' || !isValidEmail($email)) {
                respond(false, 'Please provide a valid email address.');
            }
            if ($password === '' || !isValidPassword($password)) {
                respond(false, 'Password must be at least 6 characters.');
            }

            // Check if email already exists
            $users = readJsonFile(USERS_FILE);
            foreach ($users as $user) {
                if (strtolower($user['email'] ?? '') === strtolower($email)) {
                    respond(false, 'This email is already registered. Please use a different email or login.');
                }
            }

            // Create new user
            $newUser = [
                'id' => 'user_' . uniqid() . '.' . time(),
                'name' => $name,
                'email' => strtolower($email),
                'organization' => $organization,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'user',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];

            $users[] = $newUser;

            if (!writeJsonFile(USERS_FILE, $users)) {
                respond(false, 'Failed to save user data. Please try again.', [], 500);
            }

            // Return user data (without password)
            $returnUser = $newUser;
            unset($returnUser['password']);

            respond(true, 'Registration successful! Welcome to IRECSTEM 2026.', [
                'user' => $returnUser
            ]);
            break;

        case 'login':
            $email = sanitize($input['email'] ?? '');
            $password = $input['password'] ?? '';

            // Validation
            if ($email === '' || $password === '') {
                respond(false, 'Please enter your email and password.');
            }

            // Find user
            $users = readJsonFile(USERS_FILE);
            $foundUser = null;

            foreach ($users as $user) {
                if (strtolower($user['email'] ?? '') === strtolower($email)) {
                    $foundUser = $user;
                    break;
                }
            }

            if (!$foundUser || !password_verify($password, $foundUser['password'])) {
                respond(false, 'Invalid email or password. Please try again.');
            }

            // Create session
            $token = generateToken();
            $sessions = readJsonFile(SESSIONS_FILE);

            // Remove old sessions for this user
            $sessions = array_filter($sessions, function($s) use ($foundUser) {
                return $s['user_id'] !== $foundUser['id'];
            });
            $sessions = array_values($sessions);

            // Add new session
            $sessions[] = [
                'token' => $token,
                'user_id' => $foundUser['id'],
                'created_at' => date('c'),
                'expires' => time() + (7 * 24 * 60 * 60) // 7 days
            ];

            writeJsonFile(SESSIONS_FILE, $sessions);

            // Return user data
            $returnUser = $foundUser;
            unset($returnUser['password']);

            respond(true, 'Login successful! Welcome back.', [
                'user' => $returnUser,
                'token' => $token
            ]);
            break;

        case 'logout':
            $headers = getallheaders();
            $token = $headers['Authorization'] ?? $headers['authorization'] ?? null;

            if ($token) {
                $sessions = readJsonFile(SESSIONS_FILE);
                $sessions = array_filter($sessions, function($s) use ($token) {
                    return $s['token'] !== $token;
                });
                writeJsonFile(SESSIONS_FILE, array_values($sessions));
            }

            respond(true, 'Logged out successfully.');
            break;

        case 'me':
            $user = getCurrentUser();
            if (!$user) {
                respond(false, 'Not authenticated.', [], 401);
            }
            respond(true, 'User retrieved.', ['user' => $user]);
            break;

        // ============================================
        // CONTACT FORM
        // ============================================

        case 'contact':
            $name = sanitize($input['name'] ?? '');
            $email = sanitize($input['email'] ?? '');
            $subject = sanitize($input['subject'] ?? '');
            $message = sanitize($input['message'] ?? '');

            // Validation
            if ($name === '') {
                respond(false, 'Please provide your name.');
            }
            if ($email === '' || !isValidEmail($email)) {
                respond(false, 'Please provide a valid email address.');
            }
            if ($message === '') {
                respond(false, 'Please enter your message.');
            }

            // Save contact
            $contacts = readJsonFile(CONTACTS_FILE);
            $newContact = [
                'id' => 'contact_' . uniqid() . '.' . time(),
                'name' => $name,
                'email' => strtolower($email),
                'subject' => $subject ?: 'No Subject',
                'message' => $message,
                'status' => 'new',
                'created_at' => date('c')
            ];

            $contacts[] = $newContact;

            if (!writeJsonFile(CONTACTS_FILE, $contacts)) {
                respond(false, 'Failed to save your message. Please try again.', [], 500);
            }

            respond(true, 'Thank you! Your message has been saved. Our team will follow up with you within 24–48 hours.');
            break;

        // ============================================
        // PAPER SUBMISSION
        // ============================================

        case 'paper':
            $title = sanitize($input['title'] ?? '');
            $author = sanitize($input['author'] ?? '');
            $email = sanitize($input['email'] ?? '');
            $track = sanitize($input['track'] ?? '');
            $fileName = sanitize($input['file_name'] ?? '');
            $fileData = $input['file_data'] ?? '';

            // Validation
            if ($title === '') {
                respond(false, 'Please provide a paper title.');
            }
            if ($author === '') {
                respond(false, 'Please provide the author name.');
            }
            if ($email === '' || !isValidEmail($email)) {
                respond(false, 'Please provide a valid email address.');
            }
            if ($fileName === '' || $fileData === '') {
                respond(false, 'Please attach your paper file (PDF, DOC, or DOCX).');
            }

            // Validate file type
            $allowedTypes = ['pdf', 'doc', 'docx'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedTypes)) {
                respond(false, 'Invalid file type. Only PDF, DOC, and DOCX files are allowed.');
            }

            // Validate track
            $validTracks = ['Science', 'Technology', 'Education', 'Management'];
            if (!in_array($track, $validTracks)) {
                $track = 'Science';
            }

            // Check for duplicate title
            $papers = readJsonFile(PAPERS_FILE);
            foreach ($papers as $paper) {
                if (strtolower($paper['title'] ?? '') === strtolower($title)) {
                    respond(false, 'A paper with this title has already been submitted.');
                }
            }

            // Save file
            $paperId = 'paper_' . uniqid() . '.' . time();
            $uploadDir = $baseDir . '/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $safeFileName = $paperId . '.' . $ext;
            $filePath = $uploadDir . $safeFileName;

            // Decode and save file
            $fileContent = base64_decode($fileData);
            if ($fileContent === false || file_put_contents($filePath, $fileContent) === false) {
                respond(false, 'Failed to upload file. Please try again.', [], 500);
            }

            // Save paper
            $newPaper = [
                'id' => $paperId,
                'title' => $title,
                'author' => $author,
                'email' => strtolower($email),
                'track' => $track,
                'file_name' => $fileName,
                'file_path' => $safeFileName,
                'status' => 'submitted',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];

            $papers[] = $newPaper;

            if (!writeJsonFile(PAPERS_FILE, $papers)) {
                // Clean up file if database save fails
                @unlink($filePath);
                respond(false, 'Failed to save your paper. Please try again.', [], 500);
            }

            respond(true, 'Your paper has been submitted successfully! We will review it and notify you of the outcome.', [
                'paper_id' => $newPaper['id']
            ]);
            break;

        case 'delete_paper':
            $user = getCurrentUser();
            if (!$user) {
                respond(false, 'Please login to delete your paper.', [], 401);
            }

            $paperId = sanitize($input['paper_id'] ?? '');
            if ($paperId === '') {
                respond(false, 'Paper ID is required.');
            }

            $papers = readJsonFile(PAPERS_FILE);
            $paperFound = false;
            $paperToDelete = null;

            foreach ($papers as $key => $paper) {
                if ($paper['id'] === $paperId) {
                    // Check if user owns this paper
                    if (strtolower($paper['email']) !== strtolower($user['email'])) {
                        respond(false, 'You can only delete your own papers.');
                    }
                    $paperToDelete = $paper;
                    unset($papers[$key]);
                    $paperFound = true;
                    break;
                }
            }

            if (!$paperFound) {
                respond(false, 'Paper not found.');
            }

            $papers = array_values($papers);

            if (!writeJsonFile(PAPERS_FILE, $papers)) {
                respond(false, 'Failed to delete paper. Please try again.', [], 500);
            }

            // Delete associated file
            if ($paperToDelete && isset($paperToDelete['file_path'])) {
                $filePath = $baseDir . '/uploads/' . $paperToDelete['file_path'];
                @unlink($filePath);
            }

            respond(true, 'Paper deleted successfully.');
            break;

        case 'get_paper':
            $user = getCurrentUser();
            if (!$user) {
                respond(false, 'Please login to view paper.', [], 401);
            }

            $paperId = sanitize($input['paper_id'] ?? '');
            if ($paperId === '') {
                respond(false, 'Paper ID is required.');
            }

            $papers = readJsonFile(PAPERS_FILE);
            foreach ($papers as $paper) {
                if ($paper['id'] === $paperId) {
                    // Check if user owns this paper
                    if (strtolower($paper['email']) !== strtolower($user['email'])) {
                        respond(false, 'You can only view your own papers.');
                    }
                    respond(true, 'Paper retrieved.', ['paper' => $paper]);
                }
            }
            respond(false, 'Paper not found.');
            break;

        case 'my_papers':
            // Get papers for current user
            $user = getCurrentUser();
            if (!$user) {
                respond(false, 'Please login to view your papers.', [], 401);
            }

            $papers = readJsonFile(PAPERS_FILE);
            $userPapers = array_filter($papers, function($paper) use ($user) {
                return strtolower($paper['email'] ?? '') === strtolower($user['email']);
            });

            respond(true, 'Your papers retrieved.', [
                'papers' => array_values($userPapers)
            ]);
            break;

        // ============================================
        // ADMIN FUNCTIONS (Require Admin Role)
        // ============================================

        case 'admin_users':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }
            $users = readJsonFile(USERS_FILE);
            $publicUsers = array_map(function($user) {
                unset($user['password']);
                return $user;
            }, $users);
            respond(true, 'Users retrieved.', ['users' => $publicUsers]);
            break;

        case 'admin_create_user':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $name = sanitize($input['name'] ?? '');
            $email = sanitize($input['email'] ?? '');
            $organization = sanitize($input['organization'] ?? '');
            $password = $input['password'] ?? '';
            $role = sanitize($input['role'] ?? 'user');

            if ($name === '' || $email === '' || $password === '') {
                respond(false, 'Name, email, and password are required.');
            }

            if (!isValidEmail($email)) {
                respond(false, 'Please provide a valid email.');
            }

            if (!isValidPassword($password)) {
                respond(false, 'Password must be at least 6 characters.');
            }

            if (!in_array($role, ['user', 'admin'])) {
                $role = 'user';
            }

            // Check if email already exists
            $users = readJsonFile(USERS_FILE);
            foreach ($users as $user) {
                if (strtolower($user['email'] ?? '') === strtolower($email)) {
                    respond(false, 'This email is already registered.');
                }
            }

            $newUser = [
                'id' => ($role === 'admin' ? 'admin_' : 'user_') . uniqid() . '.' . time(),
                'name' => $name,
                'email' => strtolower($email),
                'organization' => $organization,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];

            $users[] = $newUser;
            writeJsonFile(USERS_FILE, $users);

            unset($newUser['password']);
            respond(true, ($role === 'admin' ? 'Admin' : 'User') . ' created successfully!', ['user' => $newUser]);
            break;

        case 'admin_update_user':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $userId = sanitize($input['user_id'] ?? '');
            $name = sanitize($input['name'] ?? '');
            $organization = sanitize($input['organization'] ?? '');
            $role = sanitize($input['role'] ?? '');

            if ($userId === '') {
                respond(false, 'User ID is required.');
            }

            $users = readJsonFile(USERS_FILE);
            $found = false;

            foreach ($users as &$u) {
                if ($u['id'] === $userId) {
                    if ($name !== '') $u['name'] = $name;
                    if ($organization !== '') $u['organization'] = $organization;
                    if ($role !== '' && in_array($role, ['user', 'admin'])) $u['role'] = $role;
                    $u['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                respond(false, 'User not found.');
            }

            writeJsonFile(USERS_FILE, $users);
            respond(true, 'User updated successfully.');
            break;

        case 'admin_contacts':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $contacts = readJsonFile(CONTACTS_FILE);
            respond(true, 'Contacts retrieved.', ['contacts' => $contacts]);
            break;

        case 'admin_papers':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }
            $papers = readJsonFile(PAPERS_FILE);
            respond(true, 'Papers retrieved.', ['papers' => $papers]);
            break;

        case 'admin_update_paper':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $paperId = sanitize($input['paper_id'] ?? '');
            $status = sanitize($input['status'] ?? '');
            $reviewNotes = sanitize($input['review_notes'] ?? '');

            $validStatuses = ['submitted', 'under_review', 'accepted', 'rejected', 'revision_requested'];
            if (!in_array($status, $validStatuses)) {
                respond(false, 'Invalid status.');
            }

            $papers = readJsonFile(PAPERS_FILE);
            $found = false;

            foreach ($papers as &$paper) {
                if ($paper['id'] === $paperId) {
                    $paper['status'] = $status;
                    $paper['review_notes'] = $reviewNotes;
                    $paper['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                respond(false, 'Paper not found.');
            }

            writeJsonFile(PAPERS_FILE, $papers);
            respond(true, 'Paper status updated.');
            break;

        case 'admin_delete_user':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $userId = sanitize($input['user_id'] ?? '');
            $users = readJsonFile(USERS_FILE);
            $originalCount = count($users);

            $users = array_filter($users, function($user) use ($userId) {
                return $user['id'] !== $userId;
            });

            if (count($users) === $originalCount) {
                respond(false, 'User not found.');
            }

            writeJsonFile(USERS_FILE, array_values($users));
            respond(true, 'User deleted.');
            break;

        case 'admin_delete_contact':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $contactId = sanitize($input['contact_id'] ?? '');
            $contacts = readJsonFile(CONTACTS_FILE);
            $originalCount = count($contacts);

            $contacts = array_filter($contacts, function($contact) use ($contactId) {
                return $contact['id'] !== $contactId;
            });

            if (count($contacts) === $originalCount) {
                respond(false, 'Contact not found.');
            }

            writeJsonFile(CONTACTS_FILE, array_values($contacts));
            respond(true, 'Contact deleted.');
            break;

        case 'admin_delete_paper':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $paperId = sanitize($input['paper_id'] ?? '');
            $papers = readJsonFile(PAPERS_FILE);
            $originalCount = count($papers);

            $papers = array_filter($papers, function($paper) use ($paperId) {
                return $paper['id'] !== $paperId;
            });

            if (count($papers) === $originalCount) {
                respond(false, 'Paper not found.');
            }

            writeJsonFile(PAPERS_FILE, array_values($papers));
            respond(true, 'Paper deleted.');
            break;

        case 'admin_mark_contact_read':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $contactId = sanitize($input['contact_id'] ?? '');
            $contacts = readJsonFile(CONTACTS_FILE);
            $found = false;

            foreach ($contacts as &$contact) {
                if ($contact['id'] === $contactId) {
                    $contact['status'] = 'read';
                    $contact['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                respond(false, 'Contact not found.');
            }

            writeJsonFile(CONTACTS_FILE, $contacts);
            respond(true, 'Contact marked as read.');
            break;

        // ============================================
        // PASSWORD RESET (Simple Token-Based)
        // ============================================

        case 'forgot_password':
            $email = sanitize($input['email'] ?? '');

            if ($email === '' || !isValidEmail($email)) {
                respond(false, 'Please provide a valid email address.');
            }

            $users = readJsonFile(USERS_FILE);
            $userFound = false;

            foreach ($users as &$user) {
                if (strtolower($user['email'] ?? '') === strtolower($email)) {
                    // Generate reset token (in production, send via email)
                    $resetToken = bin2hex(random_bytes(16));
                    $user['reset_token'] = $resetToken;
                    $user['reset_expires'] = time() + (60 * 60); // 1 hour
                    $userFound = true;
                    break;
                }
            }

            if ($userFound) {
                writeJsonFile(USERS_FILE, $users);
                // In production, send email with reset link
                // For demo, return token in response
                respond(true, 'Password reset instructions have been sent to your email.', [
                    'reset_token' => $resetToken // Remove in production!
                ]);
            } else {
                // Don't reveal if email exists
                respond(true, 'If an account exists with this email, reset instructions have been sent.');
            }
            break;

        case 'reset_password':
            $token = sanitize($input['token'] ?? '');
            $newPassword = $input['new_password'] ?? '';

            if ($token === '' || $newPassword === '') {
                respond(false, 'Token and new password are required.');
            }

            if (!isValidPassword($newPassword)) {
                respond(false, 'Password must be at least 6 characters.');
            }

            $users = readJsonFile(USERS_FILE);
            $found = false;

            foreach ($users as &$user) {
                if (($user['reset_token'] ?? '') === $token && ($user['reset_expires'] ?? 0) > time()) {
                    $user['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    $user['updated_at'] = date('c');
                    unset($user['reset_token']);
                    unset($user['reset_expires']);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                respond(false, 'Invalid or expired reset token.');
            }

            writeJsonFile(USERS_FILE, $users);
            respond(true, 'Password has been reset successfully. You can now login.');
            break;

        // ============================================
        // UPDATE USER PROFILE
        // ============================================

        case 'update_profile':
            $user = getCurrentUser();
            if (!$user) {
                respond(false, 'Please login to update your profile.', [], 401);
            }

            $name = sanitize($input['name'] ?? '');
            $organization = sanitize($input['organization'] ?? '');

            $users = readJsonFile(USERS_FILE);
            $found = false;

            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    if ($name !== '') $u['name'] = $name;
                    if ($organization !== '') $u['organization'] = $organization;
                    $u['updated_at'] = date('c');
                    $found = true;
                    $updatedUser = $u;
                    unset($updatedUser['password']);
                    break;
                }
            }

            if (!$found) {
                respond(false, 'User not found.');
            }

            writeJsonFile(USERS_FILE, $users);
            respond(true, 'Profile updated successfully.', ['user' => $updatedUser]);
            break;

        case 'change_password':
            $user = getCurrentUser();
            if (!$user) {
                respond(false, 'Please login to change your password.', [], 401);
            }

            $currentPassword = $input['current_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';

            if ($currentPassword === '' || $newPassword === '') {
                respond(false, 'Current and new password are required.');
            }

            if (!isValidPassword($newPassword)) {
                respond(false, 'New password must be at least 6 characters.');
            }

            $users = readJsonFile(USERS_FILE);
            $found = false;

            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    if (!password_verify($currentPassword, $u['password'])) {
                        respond(false, 'Current password is incorrect.');
                    }
                    $u['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    $u['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                respond(false, 'User not found.');
            }

            writeJsonFile(USERS_FILE, $users);
            respond(true, 'Password changed successfully.');
            break;

        // ============================================
        // CREATE ADMIN ACCOUNT (One-time setup)
        // ============================================

        case 'create_admin':
            // Check if admin already exists
            $users = readJsonFile(USERS_FILE);
            foreach ($users as $user) {
                if (($user['role'] ?? 'user') === 'admin') {
                    respond(false, 'Admin account already exists.');
                }
            }

            $name = sanitize($input['name'] ?? '');
            $email = sanitize($input['email'] ?? '');
            $password = $input['password'] ?? '';

            if ($name === '' || $email === '' || $password === '') {
                respond(false, 'Name, email, and password are required.');
            }

            if (!isValidEmail($email)) {
                respond(false, 'Please provide a valid email.');
            }

            if (!isValidPassword($password)) {
                respond(false, 'Password must be at least 6 characters.');
            }

            $adminUser = [
                'id' => 'admin_' . uniqid() . '.' . time(),
                'name' => $name,
                'email' => strtolower($email),
                'organization' => 'IRECSTEM 2026',
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];

            $users[] = $adminUser;
            writeJsonFile(USERS_FILE, $users);

            unset($adminUser['password']);
            respond(true, 'Admin account created successfully!', ['user' => $adminUser]);
            break;

        // ============================================
        // ADMIN STATS
        // ============================================

        case 'admin_stats':
            if (!isAdmin()) {
                respond(false, 'Admin access required.', [], 403);
            }

            $users = readJsonFile(USERS_FILE);
            $papers = readJsonFile(PAPERS_FILE);
            $contacts = readJsonFile(CONTACTS_FILE);

            // Count by status
            $paperStats = [
                'total' => count($papers),
                'submitted' => 0,
                'under_review' => 0,
                'accepted' => 0,
                'rejected' => 0,
                'revision_requested' => 0
            ];

            foreach ($papers as $paper) {
                $status = $paper['status'] ?? 'submitted';
                if (isset($paperStats[$status])) {
                    $paperStats[$status]++;
                }
            }

            // Count by track
            $trackStats = [
                'Science' => 0,
                'Technology' => 0,
                'Education' => 0,
                'Management' => 0
            ];

            foreach ($papers as $paper) {
                $track = $paper['track'] ?? 'Science';
                if (isset($trackStats[$track])) {
                    $trackStats[$track]++;
                }
            }

            // Contact stats
            $contactStats = [
                'total' => count($contacts),
                'new' => 0,
                'read' => 0
            ];

            foreach ($contacts as $contact) {
                $status = $contact['status'] ?? 'new';
                if ($status === 'new') $contactStats['new']++;
                else $contactStats['read']++;
            }

            respond(true, 'Stats retrieved.', [
                'users' => count($users),
                'papers' => $paperStats,
                'contacts' => $contactStats
            ]);
            break;

        default:
            respond(false, 'Unsupported action: ' . $action, [], 400);
    }
} else {
    respond(false, 'Method not allowed. Use GET or POST.', [], 405);
}
