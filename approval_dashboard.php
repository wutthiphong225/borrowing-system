<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$alertScript = '';

// Handle approval
if (isset($_POST['approve_borrowing'])) {
    $borrowing_id = $_POST['borrowing_id'];
    $pdo->beginTransaction();
    try {
        // Get borrowing details
        $stmt = $pdo->prepare("SELECT * FROM borrowings WHERE id = ? AND approval_status = 'pending'");
        $stmt->execute([$borrowing_id]);
        $borrowing = $stmt->fetch();
        
        if ($borrowing) {
            // Check equipment availability
            $stmt = $pdo->prepare("SELECT quantity FROM equipment WHERE id = ?");
            $stmt->execute([$borrowing['equipment_id']]);
            $available = $stmt->fetchColumn();
            
            if ($borrowing['quantity'] <= $available) {
                // Approve borrowing
                $stmt = $pdo->prepare("UPDATE borrowings SET approval_status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $borrowing_id]);
                
                // Deduct equipment quantity
                $stmt = $pdo->prepare("UPDATE equipment SET quantity = quantity - ? WHERE id = ?");
                $stmt->execute([$borrowing['quantity'], $borrowing['equipment_id']]);
                
                $pdo->commit();
                $alertScript = "$.alert({ title: 'สำเร็จ', content: 'อนุมัติการยืมอุปกรณ์เรียบร้อยแล้ว', type: 'green', theme: 'modern', icon: 'bi bi-check-circle-fill' });";
            } else {
                $pdo->rollBack();
                $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'อุปกรณ์มีจำนวนไม่เพียงพอ', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
            }
        } else {
            throw new Exception("ไม่พบรายการที่ต้องการอนุมัติ");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $alertScript = "$.alert({ title: 'เกิดข้อผิดพลาด', content: 'การอนุมัติล้มเหลว: " . addslashes($e->getMessage()) . "', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
    }
}

// Handle rejection
if (isset($_POST['reject_borrowing'])) {
    $borrowing_id = $_POST['borrowing_id'];
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE borrowings SET approval_status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND approval_status = 'pending'");
        $stmt->execute([$_SESSION['user_id'], $rejection_reason, $borrowing_id]);
        
        $pdo->commit();
        $alertScript = "$.alert({ title: 'สำเร็จ', content: 'ปฏิเสธการยืมอุปกรณ์เรียบร้อยแล้ว', type: 'orange', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
    } catch (Exception $e) {
        $pdo->rollBack();
        $alertScript = "$.alert({ title: 'เกิดข้อผิดพลาด', content: 'การปฏิเสธล้มเหลว: " . addslashes($e->getMessage()) . "', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
    }
}

// Fetch pending borrowings with user and equipment details
$stmt = $pdo->prepare("
    SELECT b.*, u.username, e.name as equipment_name, e.image as equipment_image 
    FROM borrowings b 
    JOIN users u ON b.user_id = u.id 
    JOIN equipment e ON b.equipment_id = e.id 
    WHERE b.approval_status = 'pending' 
    ORDER BY b.created_at DESC
");
$stmt->execute();
$pending_borrowings = $stmt->fetchAll();

// Fetch all borrowings for history
$stmt = $pdo->prepare("
    SELECT b.*, u.username, e.name as equipment_name, e.image as equipment_image,
           a.username as approved_by_name
    FROM borrowings b 
    JOIN users u ON b.user_id = u.id 
    JOIN equipment e ON b.equipment_id = e.id 
    LEFT JOIN users a ON b.approved_by = a.id
    ORDER BY b.created_at DESC
");
$stmt->execute();
$all_borrowings = $stmt->fetchAll();

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Dashboard - Equipment Borrowing</title>
    
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
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .modern-shadow {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
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
        .status-pending {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }
        .status-approved {
            background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
            color: white;
        }
        .status-rejected {
            background: linear-gradient(135deg, #f87171 0%, #dc2626 100%);
            color: white;
        }
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">อนุมัติคำร้อง</span>
        <button id="mobile-menu-btn" class="focus:outline-none">
            <i class="bi bi-list text-2xl"></i>
        </button>
    </div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full relative overflow-hidden pt-16 md:pt-0">
        <!-- Top Bar -->
        <header class="premium-header z-10 px-4 py-2 flex justify-between items-center border-b border-gray-200/50">
            <div class="flex items-center space-x-3">
                <div class="hidden md:inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200 transition-colors" id="sidebar-toggle">
                    <i class="bi bi-chevron-left text-xs"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-800">อนุมัติคำร้อง</h1>
                </div>
            </div>
            <div class="hidden md:flex items-center space-x-4">
                <div class="relative">
                    <button class="relative p-1.5 text-gray-500 hover:text-gray-700 transition-colors hover:bg-gray-100 rounded-lg">
                        <i class="bi bi-bell text-sm"></i>
                        <span class="absolute top-0 right-0 w-1.5 h-1.5 bg-red-500 rounded-full animate-pulse"></span>
                    </button>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="user-avatar w-8 h-8 rounded-full">
                        <?php if ($adminProfile['profile_image'] && $adminProfile['profile_image'] !== 'default.jpg'): ?>
                            <img src="Uploads/profiles/<?php echo htmlspecialchars($adminProfile['profile_image']); ?>" 
                                 class="w-full h-full rounded-full object-cover avatar-fallback" alt="Profile">
                        <?php else: ?>
                            <div class="w-full h-full rounded-full bg-gradient-to-r from-purple-500 to-purple-600 flex items-center justify-center text-white font-bold text-xs avatar-fallback">
                                <?php echo strtoupper(substr($adminProfile['first_name'] ?: $adminProfile['username'], 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-semibold text-gray-800"><?php echo htmlspecialchars($adminProfile['first_name'] . ' ' . $adminProfile['last_name'] ?: $adminProfile['username']); ?></p>
                        <p class="text-xs text-gray-500">ผู้ดูแลระบบ</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 md:p-10">
            <div class="flex flex-col gap-8">
                
                <!-- Pending Requests -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInLeft modern-shadow">
                    <h3 class="text-2xl font-bold gradient-text mb-6 flex items-center">
                        <i class="bi bi-clock-history text-yellow-500 mr-3 text-2xl"></i> 
                        คำร้องที่รออนุมัติ 
                        <span class="ml-3 bg-yellow-500 text-white px-3 py-1 rounded-full text-sm font-bold">
                            <?php echo count($pending_borrowings); ?> รายการ
                        </span>
                    </h3>
                    
                    <?php if (count($pending_borrowings) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($pending_borrowings as $borrowing): ?>
                                <div class="bg-gradient-to-r from-yellow-50 to-orange-50 rounded-2xl p-6 border border-yellow-200 hover:shadow-lg transition-all duration-300">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-start space-x-4">
                                            <div class="w-16 h-16 rounded-xl overflow-hidden flex-shrink-0">
                                                <img src="Uploads/<?php echo htmlspecialchars($borrowing['equipment_image'] ?: 'default.jpg'); ?>" 
                                                     class="w-full h-full object-cover" alt="<?php echo htmlspecialchars($borrowing['equipment_name']); ?>">
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars($borrowing['equipment_name']); ?></h4>
                                                <p class="text-sm text-gray-600">ผู้ขอ: <span class="font-semibold"><?php echo htmlspecialchars($borrowing['username']); ?></span></p>
                                                <p class="text-sm text-gray-600">จำนวน: <span class="font-semibold"><?php echo $borrowing['quantity']; ?></span> ชิ้น</p>
                                                <p class="text-sm text-gray-600">วันที่ต้องการยืม: <span class="font-semibold"><?php echo date('d/m/Y', strtotime($borrowing['borrow_date'])); ?></span></p>
                                                <p class="text-xs text-gray-500 mt-2">ส่งคำร้องเมื่อ: <?php echo date('d/m/Y H:i', strtotime($borrowing['created_at'])); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="flex space-x-2">
                                            <button onclick="approveRequest(<?php echo $borrowing['id']; ?>)" 
                                                    class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-xl text-sm font-semibold transition-colors">
                                                <i class="bi bi-check-circle mr-1"></i> อนุมัติ
                                            </button>
                                            <button onclick="rejectRequest(<?php echo $borrowing['id']; ?>)" 
                                                    class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-xl text-sm font-semibold transition-colors">
                                                <i class="bi bi-x-circle mr-1"></i> ปฏิเสธ
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <i class="bi bi-check-circle text-6xl text-green-500 mb-4"></i>
                            <p class="text-gray-600 text-lg">ไม่มีคำร้องที่รออนุมัติในขณะนี้</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- All Requests History -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <h3 class="text-2xl font-bold gradient-text mb-6 flex items-center">
                        <i class="bi bi-clock-history text-purple-600 mr-3 text-2xl"></i> 
                        ประวัติคำร้องทั้งหมด
                    </h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full leading-normal">
                            <thead>
                                <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                    <th class="px-6 py-4 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">อุปกรณ์</th>
                                    <th class="px-6 py-4 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ผู้ขอ</th>
                                    <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">จำนวน</th>
                                    <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">วันที่ยืม</th>
                                    <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">สถานะ</th>
                                    <th class="px-6 py-4 border-b-2 border-gray-200 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">ผู้อนุมัติ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_borrowings as $borrowing): ?>
                                    <tr class="hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 transition-all duration-300 border-b border-gray-100">
                                        <td class="px-6 py-4 border-b border-gray-100 bg-white text-sm">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 w-10 h-10">
                                                    <img class="w-full h-full rounded-full object-cover border-2 border-gray-200" 
                                                         src="Uploads/<?php echo htmlspecialchars($borrowing['equipment_image'] ?: 'default.jpg'); ?>" alt="" />
                                                </div>
                                                <div class="ml-3">
                                                    <p class="text-gray-900 whitespace-no-wrap font-semibold"><?php echo htmlspecialchars($borrowing['equipment_name']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 border-b border-gray-100 bg-white text-sm">
                                            <span class="font-semibold"><?php echo htmlspecialchars($borrowing['username']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 border-b border-gray-100 bg-white text-sm text-center">
                                            <span class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full text-xs font-bold"><?php echo $borrowing['quantity']; ?></span>
                                        </td>
                                        <td class="px-6 py-4 border-b border-gray-100 bg-white text-sm text-center text-gray-600">
                                            <?php echo date('d/m/Y', strtotime($borrowing['borrow_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 border-b border-gray-100 bg-white text-sm text-center">
                                            <?php if ($borrowing['approval_status'] === 'pending'): ?>
                                                <span class="status-pending px-3 py-1 rounded-full text-xs font-bold">รออนุมัติ</span>
                                            <?php elseif ($borrowing['approval_status'] === 'approved'): ?>
                                                <span class="status-approved px-3 py-1 rounded-full text-xs font-bold">อนุมัติแล้ว</span>
                                            <?php elseif ($borrowing['approval_status'] === 'rejected'): ?>
                                                <span class="status-rejected px-3 py-1 rounded-full text-xs font-bold">ถูกปฏิเสธ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 border-b border-gray-100 bg-white text-sm text-center">
                                            <?php if ($borrowing['approved_by_name']): ?>
                                                <span class="text-gray-700"><?php echo htmlspecialchars($borrowing['approved_by_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
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
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.4/jquery-confirm.min.js"></script>
    
    <script>
        function approveRequest(id) {
            $.confirm({
                title: 'ยืนยันการอนุมัติ',
                content: 'คุณต้องการอนุมัติคำร้องนี้ใช่หรือไม่?',
                type: 'green',
                theme: 'modern',
                icon: 'bi bi-check-circle',
                buttons: {
                    confirm: {
                        text: 'อนุมัติ',
                        btnClass: 'btn-green',
                        action: function () {
                            // Submit approval form
                            $('<form>', {
                                method: 'POST',
                                html: '<input type="hidden" name="approve_borrowing" value="1"><input type="hidden" name="borrowing_id" value="' + id + '">'
                            }).appendTo('body').submit();
                        }
                    },
                    cancel: {
                        text: 'ยกเลิก'
                    }
                }
            });
        }

        function rejectRequest(id) {
            $.confirm({
                title: 'ปฏิเสธคำร้อง',
                content: `
                    <div class="space-y-4">
                        <p class="text-gray-700">คุณต้องการปฏิเสธคำร้องนี้ใช่หรือไม่?</p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">เหตุผลในการปฏิเสธ</label>
                            <textarea id="rejection_reason" class="w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-red-500 outline-none" rows="3" placeholder="กรุณาระบุเหตุผล..."></textarea>
                        </div>
                    </div>
                `,
                type: 'red',
                theme: 'modern',
                icon: 'bi bi-x-circle',
                buttons: {
                    confirm: {
                        text: 'ปฏิเสธ',
                        btnClass: 'btn-red',
                        action: function () {
                            const reason = $('#rejection_reason').val();
                            // Submit rejection form
                            $('<form>', {
                                method: 'POST',
                                html: '<input type="hidden" name="reject_borrowing" value="1"><input type="hidden" name="borrowing_id" value="' + id + '"><input type="hidden" name="rejection_reason" value="' + reason + '">'
                            }).appendTo('body').submit();
                        }
                    },
                    cancel: {
                        text: 'ยกเลิก'
                    }
                }
            });
        }

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

        $(document).ready(function() {
            <?php if ($alertScript): ?>
                <?php echo $alertScript; ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>


