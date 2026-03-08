<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: login.php');
    exit;
}

// Get current user profile info for header
$stmt = $pdo->prepare("SELECT student_id, first_name, last_name, profile_image FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch();

// Handle QR Code generation
$qr_data = '';
$show_qr = false;
$borrowing = null;

if (isset($_GET['generate_qr']) && isset($_GET['borrowing_id'])) {
    $borrowing_id = (int)$_GET['borrowing_id'];

    // Verify this borrowing belongs to current user and is approved/pending pickup
    $stmt = $pdo->prepare("
        SELECT b.*, e.name as equipment_name, u.username 
        FROM borrowings b 
        JOIN equipment e ON b.equipment_id = e.id 
        JOIN users u ON b.user_id = u.id 
        WHERE b.id = ? 
          AND b.user_id = ? 
          AND b.approval_status = 'approved' 
          AND b.status = 'borrowed'
          AND (b.pickup_confirmed IS NULL OR b.pickup_confirmed = 0)
    ");
    $stmt->execute([$borrowing_id, $_SESSION['user_id']]);
    $borrowing = $stmt->fetch();
} else {
    // Auto-pick latest approved borrowing when user opens page directly from menu
    $stmt = $pdo->prepare("
        SELECT b.*, e.name as equipment_name, u.username 
        FROM borrowings b 
        JOIN equipment e ON b.equipment_id = e.id 
        JOIN users u ON b.user_id = u.id 
        WHERE b.user_id = ? 
          AND b.approval_status = 'approved' 
          AND b.status = 'borrowed'
          AND (b.pickup_confirmed IS NULL OR b.pickup_confirmed = 0)
        ORDER BY b.created_at DESC, b.id DESC
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $borrowing = $stmt->fetch();
}

if ($borrowing) {
    // Create QR data with borrowing information
    $qr_data = json_encode([
        'type' => 'equipment_pickup',
        'borrowing_id' => $borrowing['id'],
        'user_id' => $_SESSION['user_id'],
        'username' => $borrowing['username'],
        'equipment_name' => $borrowing['equipment_name'],
        'quantity' => $borrowing['quantity'],
        'timestamp' => time(),
        'checksum' => md5($borrowing['id'] . $_SESSION['user_id'] . time())
    ]);
    $show_qr = true;
}

// Handle QR Code scan confirmation (admin scanned the code)
if (isset($_GET['confirm_pickup']) && isset($_GET['borrowing_id'])) {
    $borrowing_id = $_GET['borrowing_id'];
    
    // Update borrowing status to show equipment was picked up
    $stmt = $pdo->prepare("
        UPDATE borrowings 
        SET pickup_confirmed = 1, pickup_time = NOW() 
        WHERE id = ? AND user_id = ? AND approval_status = 'approved'
    ");
    $stmt->execute([$borrowing_id, $_SESSION['user_id']]);
    
    // Redirect back to dashboard
    header('Location: user_dashboard.php?pickup_success=1');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code สำหรับรับอุปกรณ์</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <!-- QRCode.js -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <style>
        body { 
            font-family: 'Prompt', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        }
        .sidebar-gradient {
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-gradient.collapsed {
            width: 80px;
        }
        .sidebar-gradient.collapsed .sidebar-text {
            opacity: 0;
            visibility: hidden;
        }
        .sidebar-gradient.collapsed .sidebar-icon {
            margin-right: 0;
        }
        .sidebar-gradient.collapsed .sidebar-header h2 {
            font-size: 0;
        }
        .sidebar-gradient.collapsed .sidebar-header .logo-icon {
            font-size: 1.5rem;
        }
        .sidebar-gradient:hover {
            box-shadow: 4px 0 30px rgba(0, 0, 0, 0.15);
        }
        .sidebar-item {
            position: relative;
            overflow: hidden;
        }
        .sidebar-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }
        .sidebar-item:hover::before,
        .sidebar-item.active::before {
            transform: translateX(0);
        }
        .sidebar-item.active {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.1) 0%, transparent 100%);
            border-left: 3px solid #667eea;
        }
        .sidebar-item:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .sidebar-toggle {
            position: absolute;
            right: -12px;
            top: 20px;
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 10;
        }
        .sidebar-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        .premium-header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        .user-avatar {
            position: relative;
            overflow: hidden;
        }
        .user-avatar::after {
            content: '';
            position: absolute;
            inset: -2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            z-index: -1;
            opacity: 0.8;
        }
        .user-avatar img,
        .user-avatar .avatar-fallback {
            position: relative;
            z-index: 1;
        }
        .qr-container {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: inline-block;
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(102, 126, 234, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
            }
        }
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">QR Code รับอุปกรณ์</span>
        <button id="mobile-menu-btn" class="focus:outline-none">
            <i class="bi bi-list text-2xl"></i>
        </button>
    </div>

    <!-- Sidebar -->
    <?php include 'sidebar_user.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full relative overflow-hidden pt-16 md:pt-0">
        <!-- Top Bar -->
        <header class="premium-header z-10 px-8 py-4 flex justify-between items-center border-b border-gray-200/50">
            <div class="flex items-center space-x-6">
                <div class="hidden md:inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200 transition-colors" id="sidebar-toggle">
                    <i class="bi bi-chevron-left text-xs"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 animate__animated animate__fadeInDown">QR Code รับอุปกรณ์</h1>
                    <p class="text-gray-500 text-xs mt-1">แสดง QR Code ให้ Admin สแกนเพื่อรับอุปกรณ์</p>
                </div>
            </div>
            <div class="hidden md:flex items-center space-x-6">
                <div class="relative">
                    <button class="relative p-2 text-gray-500 hover:text-gray-700 transition-colors hover:bg-gray-100 rounded-lg">
                        <i class="bi bi-bell text-lg"></i>
                        <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
                    </button>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="user-avatar w-10 h-10 rounded-full">
                        <?php if ($userProfile['profile_image'] && $userProfile['profile_image'] !== 'default.jpg'): ?>
                            <img src="Uploads/profiles/<?php echo htmlspecialchars($userProfile['profile_image']); ?>" 
                                 class="w-full h-full rounded-full object-cover avatar-fallback" alt="Profile">
                        <?php else: ?>
                            <div class="w-full h-full rounded-full bg-gradient-to-r from-purple-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm avatar-fallback">
                                <?php echo strtoupper(substr($userProfile['first_name'] ?: $_SESSION['username'], 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($userProfile['first_name'] . ' ' . $userProfile['last_name'] ?: $_SESSION['username']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($userProfile['student_id'] ?: 'นักศึกษา'); ?></p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 md:p-10">
            <div class="max-w-4xl mx-auto">
                <?php if ($show_qr): ?>
                    <!-- QR Code Display -->
                    <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                        <div class="text-center mb-8">
                            <h2 class="text-2xl font-bold gradient-text mb-4">
                                <i class="bi bi-qr-code-scan text-purple-600 mr-3"></i>
                                QR Code สำหรับรับอุปกรณ์
                            </h2>
                            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-lg mb-6">
                                <p class="text-blue-700 text-sm">
                                    <i class="bi bi-info-circle mr-2"></i>
                                    กรุณาแสดง QR Code นี้ให้ Admin สแกนเพื่อยืนยันการรับอุปกรณ์
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex flex-col items-center">
                            <div class="qr-container pulse-animation mb-6">
                                <div id="qrcode"></div>
                            </div>
                            
                            <div class="bg-gray-50 rounded-xl p-6 max-w-md w-full">
                                <h3 class="font-semibold text-gray-800 mb-4">รายละเอียดการรับอุปกรณ์</h3>
                                <div class="space-y-3">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">รหัสการยืม:</span>
                                        <span class="font-medium text-gray-800">#<?php echo str_pad($borrowing['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">อุปกรณ์:</span>
                                        <span class="font-medium text-gray-800"><?php echo htmlspecialchars($borrowing['equipment_name']); ?></span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">จำนวน:</span>
                                        <span class="font-medium text-gray-800"><?php echo $borrowing['quantity']; ?> ชิ้น</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-600">วันที่ยืม:</span>
                                        <span class="font-medium text-gray-800"><?php echo date('d/m/Y', strtotime($borrowing['borrow_date'])); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-6 flex gap-4">
                                <a href="user_dashboard.php" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-colors font-medium">
                                    <i class="bi bi-arrow-left mr-2"></i>
                                    กลับหน้าแดชบอร์ด
                                </a>
                                <button onclick="window.location.reload()" class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors font-medium">
                                    <i class="bi bi-arrow-clockwise mr-2"></i>
                                    รีเฟรช QR Code
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- No QR Code -->
                    <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                        <div class="text-center">
                            <div class="w-24 h-24 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-6">
                                <i class="bi bi-exclamation-triangle text-yellow-600 text-4xl"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-4">ไม่พบข้อมูล QR Code</h2>
                            <p class="text-gray-600 mb-8">
                                ไม่พบข้อมูลการยืมที่ต้องการแสดง QR Code หรือคำร้องยังไม่ได้รับการอนุมัติ
                            </p>
                            <a href="user_dashboard.php" class="inline-flex items-center px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors font-medium">
                                <i class="bi bi-house-door mr-2"></i>
                                กลับหน้าแดชบอร์ด
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    
    <script>
        // Mobile Menu Toggle
        $('#mobile-menu-btn').click(function() {
            $('#sidebar').toggleClass('-translate-x-full');
        });

        // Sidebar Toggle Functionality
        function toggleSidebar() {
            const sidebar = $('#sidebar');
            const mainToggle = $('#sidebar-toggle');
            const sidebarToggle = $('.sidebar-toggle');
            const icons = $('.sidebar-toggle i, #sidebar-toggle i');
            
            sidebar.toggleClass('collapsed');
            
            if (sidebar.hasClass('collapsed')) {
                icons.removeClass('bi-chevron-left').addClass('bi-chevron-right');
            } else {
                icons.removeClass('bi-chevron-right').addClass('bi-chevron-left');
            }
        }

        // Event listeners for toggle buttons
        $(document).on('click', '#sidebar-toggle', toggleSidebar);
        $(document).on('click', '.sidebar-toggle', toggleSidebar);

        // Generate QR Code
        <?php if ($show_qr): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const qrData = <?php echo json_encode($qr_data); ?>;
            new QRCode(document.getElementById("qrcode"), {
                text: qrData,
                width: 256,
                height: 256,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
            
            // Auto refresh every 30 seconds
            setTimeout(function() {
                window.location.reload();
            }, 30000);
        });
        <?php endif; ?>
    </script>
</body>
</html>


