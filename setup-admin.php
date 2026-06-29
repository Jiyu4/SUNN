<?php
/**
 * Admin Account Setup Script
 * Run this once to create your admin account
 *
 * Usage: Open this file in your browser after setting the credentials below
 */

header('Content-Type: text/html');

$baseDir = __DIR__ . '/data';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

define('USERS_FILE', $baseDir . '/users.json');

function readJsonFile($path) {
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return $content ? json_decode($content, true) : [];
}

function writeJsonFile($path, $data) {
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// ============================================
// CONFIGURATION - EDIT THESE VALUES
// ============================================
$adminName = 'Admin';
$adminEmail = 'admin@irecstem2026.org';
$adminPassword = 'Admin123!';
// ============================================

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminName = $_POST['name'] ?? $adminName;
    $adminEmail = $_POST['email'] ?? $adminEmail;
    $adminPassword = $_POST['password'] ?? $adminPassword;

    // Validation
    if (empty($adminName) || empty($adminEmail) || empty($adminPassword)) {
        $message = 'All fields are required.';
        $messageType = 'error';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';
    } elseif (strlen($adminPassword) < 6) {
        $message = 'Password must be at least 6 characters.';
        $messageType = 'error';
    } else {
        $users = readJsonFile(USERS_FILE);

        // Check if admin already exists
        foreach ($users as $user) {
            if (($user['role'] ?? 'user') === 'admin') {
                $message = 'Admin account already exists! Delete the existing admin user first or edit users.json manually.';
                $messageType = 'error';
                break;
            }
            if (strtolower($user['email'] ?? '') === strtolower($adminEmail)) {
                $message = 'This email is already registered. Use a different email.';
                $messageType = 'error';
                break;
            }
        }

        if (empty($message)) {
            $admin = [
                'id' => 'admin_' . uniqid() . '.' . time(),
                'name' => htmlspecialchars(trim($adminName)),
                'email' => strtolower(trim($adminEmail)),
                'organization' => 'IRECSTEM 2026',
                'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role' => 'admin',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];

            $users[] = $admin;

            if (writeJsonFile(USERS_FILE, $users)) {
                $message = 'Admin account created successfully! You can now login with your admin credentials.';
                $messageType = 'success';
            } else {
                $message = 'Failed to create admin account. Check file permissions.';
                $messageType = 'error';
            }
        }
    }
}

$existingUsers = readJsonFile(USERS_FILE);
$hasAdmin = false;
foreach ($existingUsers as $user) {
    if (($user['role'] ?? 'user') === 'admin') {
        $hasAdmin = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Setup - IRECSTEM 2026</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0e17, #0f172a);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 48px;
            width: 100%;
            max-width: 480px;
        }
        h1 {
            font-size: 28px;
            color: #fff;
            margin-bottom: 8px;
            text-align: center;
        }
        .subtitle {
            color: #94a3b8;
            text-align: center;
            margin-bottom: 32px;
        }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 8px;
        }
        input {
            width: 100%;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }
        .btn {
            width: 100%;
            padding: 16px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0a0e17;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(245, 158, 11, 0.4);
        }
        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
        }
        .error {
            background: rgba(244, 63, 94, 0.15);
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #fb7185;
        }
        .warning {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }
        .info {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
        }
        .note {
            margin-top: 24px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            font-size: 13px;
            color: #94a3b8;
        }
        .note strong { color: #fbbf24; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔐 Admin Setup</h1>
        <p class="subtitle">Create your admin account</p>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($hasAdmin): ?>
            <div class="message warning">
                ⚠️ An admin account already exists. Creating a new one will result in multiple admins.
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Admin Name</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($adminName); ?>" required>
            </div>
            <div class="form-group">
                <label>Admin Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($adminEmail); ?>" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" value="<?php echo htmlspecialchars($adminPassword); ?>" required minlength="6">
            </div>
            <button type="submit" class="btn">
                Create Admin Account
            </button>
        </form>

        <div class="note">
            <strong>Note:</strong> After creating the admin account, you can access the admin dashboard by logging in with these credentials. Look for the "Admin" link in the navigation.
        </div>
    </div>
</body>
</html>
