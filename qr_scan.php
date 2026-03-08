<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Handle QR Code scanning
if (isset($_POST['qr_data'])) {
    $qr_data = json_decode($_POST['qr_data'], true);
    
    if ($qr_data && isset($qr_data['type']) && $qr_data['type'] === 'equipment_pickup') {
        $borrowing_id = $qr_data['borrowing_id'];
        $user_id = $qr_data['user_id'];
        $checksum = $qr_data['checksum'];
        
        // Verify checksum for security
        $expected_checksum = md5($borrowing_id . $user_id . $qr_data['timestamp']);
        
        if ($checksum !== $expected_checksum) {
            echo json_encode(['success' => false, 'message' => 'QR Code ไม่ถูกต้อง']);
            exit;
        }
        
        // Check if QR code is not too old (5 minutes)
        if (time() - $qr_data['timestamp'] > 300) {
            echo json_encode(['success' => false, 'message' => 'QR Code หมดอายุ กรุณาขอใหม่']);
            exit;
        }
        
        // Get borrowing details
        $stmt = $pdo->prepare("
            SELECT b.*, e.name as equipment_name, u.username, u.first_name, u.last_name 
            FROM borrowings b 
            JOIN equipment e ON b.equipment_id = e.id 
            JOIN users u ON b.user_id = u.id 
            WHERE b.id = ? AND b.user_id = ? AND b.approval_status = 'approved' AND b.status = 'borrowed'
        ");
        $stmt->execute([$borrowing_id, $user_id]);
        $borrowing = $stmt->fetch();
        
        if (!$borrowing) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการยืมที่ถูกต้อง']);
            exit;
        }
        
        // Check if already picked up
        if ($borrowing['pickup_confirmed']) {
            echo json_encode(['success' => false, 'message' => 'อุปกรณ์นี้ได้รับการยืนยันการรับแล้ว']);
            exit;
        }
        
        // Confirm pickup
        $pdo->beginTransaction();
        try {
            // Update borrowing
            $stmt = $pdo->prepare("
                UPDATE borrowings 
                SET pickup_confirmed = 1, pickup_time = NOW(), approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $borrowing_id]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'ยืนยันการรับอุปกรณ์สำเร็จ',
                'borrowing' => $borrowing
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Get pending pickups for display
$pending_pickups = $pdo->query("
    SELECT b.*, e.name as equipment_name, u.username, u.first_name, u.last_name 
    FROM borrowings b 
    JOIN equipment e ON b.equipment_id = e.id 
    JOIN users u ON b.user_id = u.id 
    WHERE b.approval_status = 'approved' AND b.status = 'borrowed' AND (b.pickup_confirmed IS NULL OR b.pickup_confirmed = 0)
    ORDER BY b.borrow_date DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สแกน QR Code รับอุปกรณ์</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <!-- QR Code Scanner -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

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
        #reader {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        .scan-animation {
            border: 3px solid #667eea;
            border-radius: 12px;
            animation: scan 2s infinite;
        }
        @keyframes scan {
            0%, 100% { border-color: #667eea; }
            50% { border-color: #764ba2; }
        }
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">สแกน QR Code</span>
        <button id="mobile-menu-btn" class="focus:outline-none">
            <i class="bi bi-list text-2xl"></i>
        </button>
    </div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full relative overflow-hidden pt-16 md:pt-0">
        <!-- Top Bar -->
        <header class="premium-header z-10 px-8 py-4 flex justify-between items-center border-b border-gray-200/50">
            <div class="flex items-center space-x-6">
                <div class="hidden md:inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200 transition-colors" id="sidebar-toggle">
                    <i class="bi bi-chevron-left text-xs"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 animate__animated animate__fadeInDown">สแกน QR Code รับอุปกรณ์</h1>
                    <p class="text-gray-500 text-xs mt-1">สแกน QR Code เพื่อยืนยันการรับอุปกรณ์</p>
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
                        <div class="w-full h-full rounded-full bg-gradient-to-r from-purple-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm avatar-fallback">
                            AD
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-gray-800">Admin</p>
                        <p class="text-xs text-gray-500">ผู้ดูแลระบบ</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 md:p-10">
            <div class="max-w-6xl mx-auto">
                <!-- QR Scanner Section -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow mb-8">
                    <h2 class="text-2xl font-bold gradient-text mb-6 text-center">
                        <i class="bi bi-qr-code-scan text-purple-600 mr-3"></i>
                        สแกน QR Code สำหรับรับอุปกรณ์
                    </h2>
                    
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-lg mb-6">
                        <p class="text-blue-700 text-sm">
                            <i class="bi bi-info-circle mr-2"></i>
                            กรุณาสแกน QR Code ที่ผู้ใช้แสดงเพื่อยืนยันการรับอุปกรณ์
                        </p>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-8">
                        <div>
                            <div id="reader" class="scan-animation"></div>
                            <div class="mt-4 text-center">
                                <button id="startScan" class="px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-colors font-medium">
                                    <i class="bi bi-camera mr-2"></i>
                                    เริ่มสแกน
                                </button>
                                <button id="stopScan" class="px-6 py-3 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-colors font-medium hidden">
                                    <i class="bi bi-stop-circle mr-2"></i>
                                    หยุดสแกน
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="font-semibold text-gray-800 mb-4">รายการที่รอการรับ</h3>
                            <div class="space-y-3 max-h-96 overflow-y-auto">
                                <?php if (count($pending_pickups) > 0): ?>
                                    <?php foreach ($pending_pickups as $pickup): ?>
                                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($pickup['equipment_name']); ?></p>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($pickup['first_name'] . ' ' . $pickup['last_name']); ?></p>
                                                    <p class="text-xs text-gray-500">จำนวน: <?php echo $pickup['quantity']; ?> ชิ้น</p>
                                                </div>
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs font-medium rounded-full">
                                                    รอรับ
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-gray-500 text-center py-8">ไม่มีรายการที่รอการรับ</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Confirmations -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <h3 class="text-xl font-bold gradient-text mb-6">
                        <i class="bi bi-check-circle text-green-600 mr-3"></i>
                        การยืนยันล่าสุด
                    </h3>
                    <div id="recentConfirmations" class="space-y-3">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    
    <script>
        let html5QrCode = null;
        let isScanning = false;

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

        // QR Scanner Functions
        function onScanSuccess(decodedText, decodedResult) {
            // Stop scanning
            stopScanning();
            
            // Send to server
            $.ajax({
                url: 'qr_scan.php',
                method: 'POST',
                data: { qr_data: decodedText },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccessMessage(response.message, response.borrowing);
                        loadRecentConfirmations();
                    } else {
                        showErrorMessage(response.message);
                    }
                },
                error: function() {
                    showErrorMessage('เกิดข้อผิดพลาดในการสื่อสารกับเซิร์ฟเวอร์');
                }
            });
        }

        function onScanError(errorMessage) {
            // Handle scan error silently
        }

        function startScanning() {
            console.log('เริ่มการสแกน...');
            html5QrCode = new Html5Qrcode("reader");
            
            Html5Qrcode.getCameras().then(devices => {
                console.log('พบกล้อง:', devices);
                if (devices && devices.length) {
                    const cameraId = devices[0].id;
                    console.log('ใช้กล้อง:', cameraId);
                    
                    html5QrCode.start(
                        cameraId, 
                        {
                            fps: 10,
                            qrbox: { width: 250, height: 250 }
                        },
                        onScanSuccess, 
                        onScanError
                    ).then(() => {
                        console.log('เริ่มสแกนสำเร็จ');
                        isScanning = true;
                        $('#startScan').addClass('hidden');
                        $('#stopScan').removeClass('hidden');
                    }).catch(err => {
                        console.error('Unable to start scanning', err);
                        showErrorMessage('ไม่สามารถเริ่มการสแกนได้: ' + err);
                    });
                } else {
                    console.error('ไม่พบกล้องที่ใช้ได้');
                    showErrorMessage('ไม่พบกล้องที่ใช้ได้');
                }
            }).catch(err => {
                console.error('Unable to get cameras', err);
                showErrorMessage('ไม่พบกล้อง: ' + err);
            });
        }

        function stopScanning() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    isScanning = false;
                    $('#startScan').removeClass('hidden');
                    $('#stopScan').addClass('hidden');
                }).catch(err => {
                    console.error('Unable to stop scanning', err);
                });
            }
        }

        function showSuccessMessage(message, borrowing) {
            const alertHtml = `
                <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-lg mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-check-circle-fill text-green-400 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-green-700 font-medium">${message}</p>
                            ${borrowing ? `
                                <p class="text-green-600 text-sm mt-1">
                                    อุปกรณ์: ${borrowing.equipment_name} | 
                                    ผู้รับ: ${borrowing.first_name} ${borrowing.last_name} | 
                                    จำนวน: ${borrowing.quantity} ชิ้น
                                </p>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
            
            $('#reader').parent().prepend(alertHtml);
            setTimeout(() => {
                $('.bg-green-50').fadeOut();
            }, 5000);
        }

        function showErrorMessage(message) {
            const alertHtml = `
                <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded-lg mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-exclamation-triangle-fill text-red-400 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-red-700 font-medium">${message}</p>
                        </div>
                    </div>
                </div>
            `;
            
            $('#reader').parent().prepend(alertHtml);
            setTimeout(() => {
                $('.bg-red-50').fadeOut();
            }, 5000);
        }

        function loadRecentConfirmations() {
            $.ajax({
                url: 'qr_scan.php',
                method: 'GET',
                data: { recent: true },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const confirmations = response.confirmations || [];
                        let html = '';
                        
                        if (confirmations.length > 0) {
                            confirmations.forEach(conf => {
                                html += `
                                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <p class="font-medium text-gray-800">${conf.equipment_name}</p>
                                                <p class="text-sm text-gray-600">${conf.first_name} ${conf.last_name}</p>
                                                <p class="text-xs text-gray-500">เวลา: ${conf.pickup_time}</p>
                                            </div>
                                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-full">
                                                <i class="bi bi-check-circle mr-1"></i>
                                                รับแล้ว
                                            </span>
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html = '<p class="text-gray-500 text-center py-4">ยังไม่มีการยืนยันล่าสุด</p>';
                        }
                        
                        $('#recentConfirmations').html(html);
                    }
                }
            });
        }

        // Event Listeners
        $('#startScan').click(startScanning);
        $('#stopScan').click(stopScanning);

        // Load recent confirmations on page load
        $(document).ready(function() {
            loadRecentConfirmations();
        });
    </script>
</body>
</html>


