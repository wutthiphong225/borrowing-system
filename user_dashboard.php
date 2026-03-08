<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: login.php');
    exit;
}

$alertScript = '';

// Borrow equipment
if (isset($_POST['borrow_equipment'])) {
    $items = $_POST['items'] ?? [];
    $borrow_date = $_POST['borrow_date'] ?? '';
    $today = date('Y-m-d');

    if (empty($items)) {
        $alertScript = "$.alert({ title: 'Error', content: 'Please select at least one item.', type: 'red', theme: 'modern' });";
    } elseif (empty($borrow_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $borrow_date)) {
        $alertScript = "$.alert({ title: 'Error', content: 'Please select a valid borrow date.', type: 'red', theme: 'modern' });";
    } elseif (strtotime($borrow_date) < strtotime($today)) {
        $alertScript = "$.alert({ title: 'Error', content: 'Borrow date cannot be in the past.', type: 'red', theme: 'modern' });";
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $equipment_id = isset($item['equipment_id']) ? (int)$item['equipment_id'] : 0;
                $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;

                if ($equipment_id <= 0 || $quantity <= 0) {
                    throw new Exception('Invalid borrow item data.');
                }

                $stmt = $pdo->prepare("SELECT quantity FROM equipment WHERE id = ?");
                $stmt->execute([$equipment_id]);
                $available = $stmt->fetchColumn();

                if ($available === false) {
                    throw new Exception('Equipment not found.');
                }
                if ($quantity > (int)$available) {
                    throw new Exception('Requested quantity exceeds available stock.');
                }

                $stmt = $pdo->prepare("INSERT INTO borrowings (user_id, equipment_id, quantity, borrow_date, status, approval_status) VALUES (?, ?, ?, ?, 'borrowed', 'pending')");
                $stmt->execute([$_SESSION['user_id'], $equipment_id, $quantity, $borrow_date]);
            }

            $pdo->commit();
            $alertScript = "$.alert({ title: 'Success', content: 'Borrow request submitted successfully.', type: 'blue', theme: 'modern', icon: 'bi bi-clock-history' });";
        } catch (Exception $e) {
            $pdo->rollBack();
            $alertScript = "$.alert({ title: 'Error', content: '" . addslashes($e->getMessage()) . "', type: 'red', theme: 'modern' });";
        }
    }
}
// Return equipment
if (isset($_GET['return_borrowing'])) {
    $borrowing_id = $_GET['return_borrowing'];
    $pdo->beginTransaction();
    try {
        // Update borrowing status and set return date
        $stmt = $pdo->prepare("UPDATE borrowings SET status = 'returned', return_date = CURDATE() WHERE id = ? AND user_id = ? AND status = 'borrowed'");
        $stmt->execute([$borrowing_id, $_SESSION['user_id']]);

        if ($stmt->rowCount() === 0) {
            // Check if already returned
            $checkStmt = $pdo->prepare("SELECT status FROM borrowings WHERE id = ? AND user_id = ?");
            $checkStmt->execute([$borrowing_id, $_SESSION['user_id']]);
            $existing = $checkStmt->fetch();

            if ($existing && $existing['status'] === 'returned') {
                $pdo->commit();
                $alertScript = "$.alert({ title: 'แจ้งเตือน', content: 'รายการนี้ได้ถูกคืนไปเรียบร้อยแล้ว', type: 'orange', theme: 'modern', icon: 'bi bi-check-circle-fill' });";
            } else {
                throw new Exception("ไม่พบรายการยืม หรือสถานะไม่ถูกต้อง");
            }
        } else {
            // Restore equipment quantity
            $stmt = $pdo->prepare("SELECT equipment_id, quantity FROM borrowings WHERE id = ?");
            $stmt->execute([$borrowing_id]);
            $borrowing = $stmt->fetch();
            if ($borrowing) {
                $stmt = $pdo->prepare("UPDATE equipment SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$borrowing['quantity'], $borrowing['equipment_id']]);
            }
            $pdo->commit();
            $alertScript = "$.alert({ title: 'สำเร็จ', content: 'คืนอุปกรณ์เรียบร้อยแล้ว', type: 'green', theme: 'modern', icon: 'bi bi-check-circle-fill' });";
        }

        // Clear URL query parameter
        $alertScript .= " if (window.history.replaceState) { window.history.replaceState(null, null, window.location.pathname); }";
    } catch (Exception $e) {
        $pdo->rollBack();
        $alertScript = "$.alert({ title: 'เกิดข้อผิดพลาด', content: 'การคืนล้มเหลว: " . addslashes($e->getMessage()) . "', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
    }
}

// Fetch equipment and borrowings with user profile info
$equipment = $pdo->query("SELECT e.*, c.name AS category_name FROM equipment e JOIN categories c ON e.category_id = c.id WHERE e.quantity > 0")->fetchAll();
$stmt = $pdo->prepare("SELECT b.*, e.name, e.image, u.student_id, u.first_name, u.last_name, u.profile_image FROM borrowings b JOIN equipment e ON b.equipment_id = e.id JOIN users u ON b.user_id = u.id WHERE b.user_id = ? ORDER BY b.borrow_date DESC");
$stmt->execute([$_SESSION['user_id']]);
$borrowings = $stmt->fetchAll();

// Get current user profile info
$stmt = $pdo->prepare("SELECT student_id, first_name, last_name, profile_image FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch();

// Calculate stats
$stats_borrowed = 0;
$stats_returned = 0;
$stats_pending = 0;
foreach ($borrowings as $b) {
    if ($b['status'] === 'borrowed') {
        if ($b['approval_status'] === 'approved') {
            $stats_borrowed += $b['quantity'];
        } elseif ($b['approval_status'] === 'pending') {
            $stats_pending += $b['quantity'];
        }
    } else {
        $stats_returned += $b['quantity'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Equipment Borrowing</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- jQuery Confirm CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.4/jquery-confirm.min.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

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
        .card-hover {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        .modern-shadow {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1), 0 1px 8px rgba(0, 0, 0, 0.06);
        }
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .modern-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .modern-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .modern-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        .modern-button:hover::before {
            left: 100%;
        }
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        ::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #764ba2 0%, #667eea 100%); }
        /* Enhanced jQuery Confirm */
        .jconfirm .jconfirm-box {
            border-radius: 20px !important;
            padding: 30px !important;
            max-width: 450px !important;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        }
        .jconfirm.jconfirm-modern .jconfirm-box .jconfirm-buttons {
            float: none !important;
            display: flex;
            gap: 12px;
            justify-content: center;
            padding-top: 20px;
        }
        .jconfirm.jconfirm-modern .jconfirm-box .jconfirm-buttons button {
            border-radius: 15px !important;
            font-weight: 600 !important;
            flex: 1;
            padding: 12px 20px;
            transition: all 0.3s ease;
        }
        .stats-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }
        .stats-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        .equipment-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .equipment-card:hover {
            transform: translateY(-12px) scale(1.03);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        .equipment-card .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(0, 0, 0, 0.7) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .equipment-card:hover .image-overlay {
            opacity: 1;
        }
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">Borrowing System</span>
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
                    <h1 class="text-2xl font-bold text-gray-800 animate__animated animate__fadeInDown">ยินดีต้อนรับ, <span class="text-purple-600"><?php echo htmlspecialchars($userProfile['first_name'] ?: $_SESSION['username']); ?></span></h1>
                    <p class="text-gray-500 text-xs mt-1">จัดการการยืม-คืนอุปกรณ์ของคุณได้ที่นี่</p>
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

        <!-- Scrollable Content -->
        <div class="flex-1 overflow-y-auto p-6 md:p-10">
            <div class="flex flex-col gap-10">
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Stats Card -->
                    <div class="lg:col-span-1">
                        <div class="stats-card rounded-3xl p-6 h-full border-t-4 border-blue-500 animate__animated animate__fadeInDown modern-shadow">
                            <h3 class="text-xl font-bold gradient-text mb-6 flex items-center">
                                <i class="bi bi-pie-chart-fill text-blue-500 mr-2 text-xl"></i> สรุปข้อมูลการยืม
                            </h3>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center p-4 bg-gradient-to-r from-orange-50 to-orange-100 rounded-xl border border-orange-200 card-hover">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-orange-400 to-orange-600 flex items-center justify-center text-white mr-4 shadow-lg">
                                            <i class="bi bi-box-seam text-lg"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-600 font-semibold mb-1">กำลังยืม</p>
                                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats_borrowed; ?> <span class="text-xs font-normal text-gray-500">ชิ้น</span></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-between items-center p-4 bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl border border-blue-200 card-hover">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-400 to-blue-600 flex items-center justify-center text-white mr-4 shadow-lg">
                                            <i class="bi bi-clock-history text-lg"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-600 font-semibold mb-1">รออนุมัติ</p>
                                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats_pending; ?> <span class="text-xs font-normal text-gray-500">ชิ้น</span></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-between items-center p-4 bg-gradient-to-r from-green-50 to-green-100 rounded-xl border border-green-200 card-hover">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-400 to-green-600 flex items-center justify-center text-white mr-4 shadow-lg">
                                            <i class="bi bi-check-circle text-lg"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-600 font-semibold mb-1">คืนแล้ว</p>
                                            <p class="text-2xl font-bold text-gray-800"><?php echo $stats_returned; ?> <span class="text-xs font-normal text-gray-500">ชิ้น</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button onclick="document.getElementById('history-section').scrollIntoView({behavior: 'smooth'})" class="block w-full mt-6 text-center bg-gradient-to-r from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 text-blue-600 font-semibold py-3 rounded-xl transition-all duration-300 transform hover:scale-105">
                                ดูรายละเอียด <i class="bi bi-arrow-down-circle ml-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Cart Section -->
                    <div class="lg:col-span-2">
                        <div class="glass-effect rounded-3xl p-6 sticky top-0 z-20 animate__animated animate__fadeInDown border-t-4 border-purple-500 h-full modern-shadow">
                        <h3 class="text-xl font-bold gradient-text mb-4 flex items-center justify-between">
                            <span><i class="bi bi-cart-check text-purple-600 mr-2 text-xl"></i> รายการที่เลือก</span>
                            <span id="cart-count" class="bg-gradient-to-r from-red-500 to-pink-500 text-white text-xs font-bold px-2 py-1 rounded-full shadow-lg">0</span>
                        </h3>
                        
                        <form id="borrowForm" method="POST">
                            <input type="hidden" name="borrow_date" id="cart_borrow_date">
                            
                            <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-4 mb-4 max-h-[200px] overflow-y-auto border border-gray-200">
                                <table class="w-full">
                                    <tbody id="borrowCart" class="text-sm">
                                        <tr class="text-gray-400 text-center">
                                            <td class="py-8">ยังไม่มีรายการที่เลือก</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex flex-col md:flex-row gap-4 items-center">
                                <div id="date-display" class="hidden flex-1 p-3 bg-gradient-to-r from-purple-50 to-purple-100 rounded-xl text-xs text-purple-700 flex items-center w-full md:w-auto border border-purple-200">
                                    <i class="bi bi-calendar-event mr-2 text-purple-600"></i> วันที่ยืม: <span id="display_borrow_date" class="font-bold ml-1"></span>
                                </div>

                                <button type="submit" name="borrow_equipment" id="borrowButton" disabled 
                                        class="w-full md:w-auto bg-gradient-to-r from-gray-300 to-gray-400 text-gray-500 font-bold py-3 px-8 rounded-xl transition-all duration-300 cursor-not-allowed flex justify-center items-center ml-auto">
                                    <i class="bi bi-check2-circle mr-2"></i> ยืนยันการยืม
                                </button>
                            </div>
                        </form>
                    </div>
                    </div>
                </div>
                
                <!-- Equipment List -->
                <div class="w-full space-y-8">
                    <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInLeft modern-shadow">
                        <h3 class="text-2xl font-bold gradient-text mb-6 flex items-center">
                            <i class="bi bi-tools text-purple-600 mr-3 text-2xl"></i> อุปกรณ์ที่สามารถยืมได้
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
                            <?php foreach ($equipment as $item): ?>
                                <div class="equipment-card rounded-2xl modern-shadow overflow-hidden">
                                    <div class="relative h-56 overflow-hidden bg-gradient-to-br from-gray-100 to-gray-200">
                                        <img src="Uploads/<?php echo htmlspecialchars($item['image'] ?: 'default.jpg'); ?>" 
                                             class="w-full h-full object-cover transition-all duration-700 group-hover:scale-110" 
                                             alt="<?php echo htmlspecialchars($item['name']); ?>">
                                        <div class="image-overlay"></div>
                                        <div class="absolute top-4 right-4 glass-effect px-4 py-2 rounded-xl text-sm font-bold text-purple-700 shadow-lg">
                                            <?php echo htmlspecialchars($item['category_name']); ?>
                                        </div>
                                    </div>
                                    <div class="p-6">
                                        <h5 class="font-bold text-xl text-gray-800 mb-2 truncate"><?php echo htmlspecialchars($item['name']); ?></h5>
                                        <p class="text-sm text-gray-600 mb-4 line-clamp-2 h-12"><?php echo htmlspecialchars($item['description'] ?: 'ไม่มีรายละเอียด'); ?></p>
                                        
                                        <div class="flex justify-between items-center mt-6">
                                            <span class="text-sm font-semibold <?php echo $item['quantity'] > 0 ? 'text-green-600' : 'text-red-500'; ?>">
                                                <i class="bi bi-box-seam mr-2"></i> คงเหลือ: <?php echo $item['quantity']; ?>
                                            </span>
                                            <button onclick="openBorrowModal(<?php echo $item['id']; ?>, '<?php echo addslashes($item['name']); ?>', <?php echo $item['quantity']; ?>, '<?php echo htmlspecialchars($item['image'] ?: 'default.jpg'); ?>')" 
                                                    class="modern-button text-white px-6 py-3 rounded-2xl text-sm font-semibold shadow-lg focus:ring-4 focus:ring-purple-300">
                                                ยืมอุปกรณ์
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Borrowing History -->
                    <div id="history-section" class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                        <h3 class="text-2xl font-bold gradient-text mb-6 flex items-center">
                            <i class="bi bi-clock-history text-purple-600 mr-3 text-2xl"></i> ประวัติการยืม-คืน
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full leading-normal">
                                <thead>
                                    <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                        <th class="px-6 py-4 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider rounded-tl-2xl">อุปกรณ์</th>
                                        <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">จำนวน</th>
                                        <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">วันที่ยืม</th>
                                        <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">สถานะ</th>
                                        <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider rounded-tr-2xl">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($borrowings as $borrowing): ?>
                                        <tr class="hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 transition-all duration-300 border-b border-gray-100">
                                            <td class="px-6 py-5 border-b border-gray-100 bg-white text-sm">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 w-12 h-12">
                                                        <img class="w-full h-full rounded-full object-cover border-2 border-gray-200" src="Uploads/<?php echo htmlspecialchars($borrowing['image'] ?: 'default.jpg'); ?>" alt="" />
                                                    </div>
                                                    <div class="ml-4">
                                                        <p class="text-gray-900 whitespace-no-wrap font-semibold"><?php echo htmlspecialchars($borrowing['name']); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-5 border-b border-gray-100 bg-white text-sm text-center">
                                                <span class="bg-gradient-to-r from-purple-100 to-blue-100 text-purple-800 text-xs font-bold px-3 py-2 rounded-full"><?php echo $borrowing['quantity']; ?></span>
                                            </td>
                                            <td class="px-6 py-5 border-b border-gray-100 bg-white text-sm text-center text-gray-600">
                                                <?php echo date('d/m/Y', strtotime($borrowing['borrow_date'])); ?>
                                            </td>
                                            <td class="px-6 py-5 border-b border-gray-100 bg-white text-sm text-center">
                                                <?php 
                                                if ($borrowing['status'] === 'borrowed' && $borrowing['approval_status'] === 'pending'): 
                                                ?>
                                                    <span class="relative inline-block px-4 py-2 font-semibold text-blue-900 leading-tight">
                                                        <span aria-hidden class="absolute inset-0 bg-gradient-to-r from-blue-200 to-blue-300 opacity-70 rounded-full"></span>
                                                        <span class="relative text-sm">รออนุมัติ</span>
                                                    </span>
                                                <?php 
                                                elseif ($borrowing['status'] === 'borrowed' && $borrowing['approval_status'] === 'approved'): 
                                                ?>
                                                    <span class="relative inline-block px-4 py-2 font-semibold text-orange-900 leading-tight">
                                                        <span aria-hidden class="absolute inset-0 bg-gradient-to-r from-orange-200 to-orange-300 opacity-70 rounded-full"></span>
                                                        <span class="relative text-sm">กำลังยืม</span>
                                                    </span>
                                                <?php 
                                                elseif ($borrowing['status'] === 'borrowed' && $borrowing['approval_status'] === 'rejected'): 
                                                ?>
                                                    <span class="relative inline-block px-4 py-2 font-semibold text-red-900 leading-tight">
                                                        <span aria-hidden class="absolute inset-0 bg-gradient-to-r from-red-200 to-red-300 opacity-70 rounded-full"></span>
                                                        <span class="relative text-sm">ถูกปฏิเสธ</span>
                                                    </span>
                                                <?php 
                                                else: 
                                                ?>
                                                    <span class="relative inline-block px-4 py-2 font-semibold text-green-900 leading-tight">
                                                        <span aria-hidden class="absolute inset-0 bg-gradient-to-r from-green-200 to-green-300 opacity-70 rounded-full"></span>
                                                        <span class="relative text-sm">คืนแล้ว</span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-5 border-b border-gray-100 bg-white text-sm text-center">
                                                <?php if ($borrowing['status'] === 'borrowed' && $borrowing['approval_status'] === 'approved'): ?>
                                                    <?php if (isset($borrowing['pickup_confirmed']) && $borrowing['pickup_confirmed'] == 0): ?>
                                                        <a href="qr_pickup.php?generate_qr=1&borrowing_id=<?php echo $borrowing['id']; ?>" 
                                                           class="inline-flex items-center px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-xs font-medium mr-2" title="แสดง QR Code">
                                                            <i class="bi bi-qr-code mr-1"></i>
                                                            QR Code
                                                        </a>
                                                    <?php endif; ?>
                                                    <button onclick="confirmReturn(<?php echo $borrowing['id']; ?>)" class="text-red-500 hover:text-red-700 transition-all duration-300 transform hover:scale-110" title="คืนอุปกรณ์">
                                                        <i class="bi bi-arrow-counterclockwise text-2xl"></i>
                                                    </button>
                                                <?php elseif ($borrowing['status'] === 'borrowed' && $borrowing['approval_status'] === 'pending'): ?>
                                                    <span class="text-blue-500"><i class="bi bi-hourglass-split text-2xl"></i></span>
                                                <?php elseif ($borrowing['status'] === 'borrowed' && $borrowing['approval_status'] === 'rejected'): ?>
                                                    <span class="text-red-500"><i class="bi bi-x-circle text-2xl"></i></span>
                                                <?php else: ?>
                                                    <span class="text-green-500"><i class="bi bi-check-all text-2xl"></i></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.4/jquery-confirm.min.js"></script>
    
    <script>
        // Mobile Menu Toggle
        $('#mobile-menu-btn').click(function() {
            $('#sidebar').toggleClass('-translate-x-full');
        });

        // Sidebar Toggle Functionality - Multiple ways to trigger
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

        // Debug: Check if elements exist
        $(document).ready(function() {
            console.log('Sidebar toggle button:', $('#sidebar-toggle').length);
            console.log('Sidebar element:', $('#sidebar').length);
            
            // Test click event
            $('#sidebar-toggle').on('click', function() {
                console.log('Toggle button clicked!');
            });
        });

        // Cart Logic
        let cart = [];
        let borrowDate = '';

        function openBorrowModal(id, name, max, image) {
            let modalWidth = window.innerWidth < 768 ? '90%' : '30%';
            $.confirm({
                title: 'ยืมอุปกรณ์',
                boxWidth: modalWidth,
                useBootstrap: false,
                content: `
                    <div class="text-center mb-4">
                        <img src="Uploads/${image}" class="w-24 h-24 object-cover rounded-xl mx-auto mb-3 border-2 border-purple-200">
                        <p class="font-bold text-lg text-gray-800">${name}</p>
                        <p class="text-xs text-gray-500">จำนวนคงเหลือในคลัง: ${max}</p>
                    </div>
                    <form action="" class="formName space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 text-left">จำนวนที่ต้องการ</label>
                            <input type="number" class="quantity w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 outline-none" required min="1" max="${max}" value="1" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 text-left">วันที่ต้องการยืม</label>
                            <input type="date" class="borrow-date w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 outline-none" required min="<?php echo date('Y-m-d'); ?>" value="${borrowDate || '<?php echo date('Y-m-d'); ?>'}" ${borrowDate ? 'readonly' : ''} />
                        </div>
                    </form>`,
                theme: 'modern',
                type: 'purple',
                buttons: {
                    formSubmit: {
                        text: 'เพิ่มลงตะกร้า',
                        btnClass: 'btn-purple',
                        action: function () {
                            // ดึงค่าจาก input ภายใน modal
                            var quantity = this.$content.find('.quantity').val();
                            var date = this.$content.find('.borrow-date').val();
                            
                            if(!quantity || quantity <= 0 || quantity > max){
                                $.alert({ title: 'แจ้งเตือน', content: 'กรุณาระบุจำนวนที่ถูกต้อง', type: 'red', theme: 'modern' });
                                return false;
                            }
                            if(!date){
                                $.alert({ title: 'แจ้งเตือน', content: 'กรุณาระบุวันที่', type: 'red', theme: 'modern' });
                                return false;
                            }
                            
                            // เรียกฟังก์ชัน addToCart พร้อมส่งค่าที่ดึงมาได้
                            addToCart(id, name, quantity, date, image);
                        }
                    },
                    cancel: { text: 'ยกเลิก' }
                }
            });
        }

        function addToCart(id, name, quantity, date, image) {
            if (cart.length > 0 && borrowDate !== date) {
                $.alert({
                    title: 'แจ้งเตือน',
                    content: 'กรุณาเลือกวันที่ยืมวันเดียวกันสำหรับการทำรายการครั้งนี้',
                    type: 'orange',
                    theme: 'modern',
                    icon: 'bi bi-exclamation-circle'
                });
                return;
            }

            borrowDate = date;
            $('#cart_borrow_date').val(borrowDate);
            $('#display_borrow_date').text(new Date(borrowDate).toLocaleDateString('th-TH'));
            $('#date-display').removeClass('hidden');

            let existingItem = cart.find(item => item.equipment_id === id);
            if (existingItem) {
                existingItem.quantity = parseInt(quantity);
            } else {
                cart.push({ equipment_id: id, quantity: parseInt(quantity), name: name, image: image });
            }

            updateCartUI();
            
            // Animation for cart
            $('#borrowCart').parent().parent().addClass('animate__animated animate__pulse');
            setTimeout(() => {
                $('#borrowCart').parent().parent().removeClass('animate__animated animate__pulse');
            }, 1000);
        }

        function updateCartUI() {
            let cartBody = $('#borrowCart');
            cartBody.empty();
            
            if (cart.length === 0) {
                cartBody.html('<tr class="text-gray-400 text-center"><td class="py-8">ยังไม่มีรายการที่เลือก</td></tr>');
                $('#borrowButton').prop('disabled', true).addClass('bg-gradient-to-r from-gray-300 to-gray-400 text-gray-500 cursor-not-allowed').removeClass('modern-button text-white hover:shadow-xl');
                $('#cart-count').text('0');
                $('#date-display').addClass('hidden');
                borrowDate = '';
                return;
            }

            cart.forEach((item, index) => {
                cartBody.append(`
                    <tr class="border-b border-gray-200 last:border-0 hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 transition-all duration-300">
                        <td class="py-3 pl-3">
                            <div class="flex items-center">
                                <div class="w-10 h-10 rounded-lg overflow-hidden mr-3 flex-shrink-0">
                                    <img src="Uploads/${item.image}" class="w-full h-full object-cover" alt="${item.name}">
                                </div>
                                <div class="font-semibold text-gray-800">${item.name}</div>
                            </div>
                        </td>
                        <td class="py-3 text-center">
                            <span class="bg-gradient-to-r from-purple-100 to-blue-100 text-purple-800 text-xs font-bold px-3 py-2 rounded-full">${item.quantity}</span>
                        </td>
                        <td class="py-3 text-right pr-3">
                            <button type="button" class="text-red-400 hover:text-red-600 transition-all duration-300 transform hover:scale-110 remove-item" data-index="${index}">
                                <i class="bi bi-trash text-lg"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });

            $('#cart-count').text(cart.length);
            $('#borrowButton').prop('disabled', false).removeClass('bg-gradient-to-r from-gray-300 to-gray-400 text-gray-500 cursor-not-allowed').addClass('modern-button text-white hover:shadow-xl');

            // Update Hidden Inputs
            $('#borrowForm').find('input[name^="items"]').remove();
            let hiddenInputs = '';
            cart.forEach((item, index) => {
                hiddenInputs += `
                    <input type="hidden" name="items[${index}][equipment_id]" value="${item.equipment_id}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                `;
            });
            $('#borrowForm').append(hiddenInputs);
        }

        $(document).on('click', '.remove-item', function() {
            let index = $(this).data('index');
            cart.splice(index, 1);
            updateCartUI();
        });

        function confirmReturn(id) {
            $.confirm({
                title: 'ยืนยันการคืน',
                content: 'คุณต้องการคืนอุปกรณ์รายการนี้ใช่หรือไม่?',
                type: 'orange',
                theme: 'modern',
                icon: 'bi bi-question-circle',
                buttons: {
                    confirm: {
                        text: 'ยืนยัน',
                        btnClass: 'btn-orange',
                        action: function () {
                            window.location.href = '?return_borrowing=' + id;
                        }
                    },
                    cancel: {
                        text: 'ยกเลิก'
                    }
                }
            });
        }

        $(document).ready(function() {
            <?php if ($alertScript): ?>
                <?php echo $alertScript; ?>
            <?php endif; ?>
        });
    </script>
    
    <!-- Custom Styles for jQuery Confirm to match Tailwind -->
    <style>
        .jconfirm .jconfirm-box { border-radius: 1rem; border: none; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .jconfirm.jconfirm-modern .jconfirm-box .jconfirm-buttons button.btn-purple { background-color: #722ff9; color: white; }
        .jconfirm.jconfirm-modern .jconfirm-box .jconfirm-buttons button.btn-purple:hover { background-color: #5b21b6; }
    </style>
</body>
</html>



