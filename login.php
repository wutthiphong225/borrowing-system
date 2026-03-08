<?php
session_start();
require_once 'config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้งานและรหัสผ่าน';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            if ($user['role'] === 'admin') {
                header('Location: admins.php');
            } else {
                header('Location: user_dashboard.php');
            }
            exit;
        } else {
            $error = 'ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Equipment Borrowing System</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

    <style>
        body { font-family: 'Prompt', sans-serif; }
        .bg-gradient-primary {
            background: linear-gradient(135deg, #722ff9 0%, #a855f7 100%);
        }
    </style>
</head>
<body class="bg-gradient-primary h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate__animated animate__zoomIn">
        <div class="p-8">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4 animate__animated animate__bounceIn delay-1s">
                    <i class="bi bi-box-seam text-4xl text-[#722ff9]"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800">ยินดีต้อนรับ</h2>
                <p class="text-gray-500 text-sm mt-1">ระบบยืม-คืนอุปกรณ์</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 text-red-500 text-sm p-3 rounded-lg mb-6 flex items-center animate__animated animate__shakeX">
                    <i class="bi bi-exclamation-circle-fill mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ใช้งาน</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-person text-gray-400"></i>
                        </div>
                        <input type="text" name="username" id="username" required 
                               class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#722ff9] focus:border-transparent transition-all bg-gray-50 focus:bg-white"
                               placeholder="Enter your username">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่าน</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="bi bi-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password" id="password" required 
                               class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#722ff9] focus:border-transparent transition-all bg-gray-50 focus:bg-white"
                               placeholder="Enter your password">
                    </div>
                </div>

                <button type="submit" class="w-full bg-[#722ff9] hover:bg-[#5b21b6] text-white font-bold py-3 rounded-xl transition-all duration-300 transform hover:scale-[1.02] shadow-lg hover:shadow-purple-500/30">
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>
        <div class="bg-gray-50 px-8 py-4 text-center text-sm text-gray-500 border-t border-gray-100">
            Equipment Borrowing System
        </div>
    </div>
</body>
</html>
