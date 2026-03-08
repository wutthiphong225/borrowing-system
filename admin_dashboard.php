<?php
session_start();
require_once 'config.php';

// 1. ตรวจสอบสิทธิ์การเข้าถึง
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 2. Logic สำหรับดึงข้อมูลสถิติ (Statistics Data)
// นับจำนวนอุปกรณ์ที่พร้อมใช้งาน (Quantity > 0)
$stmt = $pdo->query("SELECT COUNT(*) FROM equipment WHERE quantity > 0");
$readyToBorrow = $stmt->fetchColumn();

// นับรายการที่กำลังถูกยืมอยู่ปัจจุบัน (Status = 'borrowed')
$stmt = $pdo->query("SELECT COUNT(*) FROM borrowings WHERE status = 'borrowed'");
$currentlyBorrowed = $stmt->fetchColumn();

// นับจำนวนสมาชิกทั้งหมด
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'");
$totalMembers = $stmt->fetchColumn();

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();

// ดึงข้อมูลสำหรับกราฟวงกลม (Pie Chart) - แยกตามหมวดหมู่
$stmt = $pdo->query("
    SELECT c.name, COUNT(b.id) as total 
    FROM categories c 
    LEFT JOIN equipment e ON c.id = e.category_id 
    LEFT JOIN borrowings b ON e.id = b.equipment_id 
    GROUP BY c.id
");
$categoryStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลสำหรับกราฟเส้น (Line Chart) - สถิติการยืม 7 วันล่าสุด
$stmt = $pdo->query("
    SELECT DATE(borrow_date) as date, COUNT(*) as count 
    FROM borrowings 
    WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(borrow_date)
    ORDER BY date ASC
");
$dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ระบบยืม-คืนอุปกรณ์</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>

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
        .topbar-toggle {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        .topbar-toggle:hover {
            color: #1e293b;
            background: #e2e8f0;
        }        .premium-header {
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
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .stats-card {
            transition: all 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        #calendar { background: white; padding: 20px; border-radius: 1rem; }
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">แผงควบคุมแอดมิน</span>
        <button id="mobile-menu-btn" class="focus:outline-none">
            <i class="bi bi-list text-2xl"></i>
        </button>
    </div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full relative overflow-hidden pt-16 md:pt-0">
        <!-- Top Bar -->
        <header class="premium-header z-10 px-4 md:px-6 py-3 flex justify-between items-center border-b border-gray-200/50">
            <div class="flex items-center space-x-3">
                <button type="button" class="topbar-toggle hidden md:inline-flex" id="sidebar-toggle" aria-label="Toggle sidebar">
                    <i class="bi bi-chevron-left text-xs"></i>
                </button>
                <div>
                    <h1 class="text-lg font-bold text-gray-800">แผงควบคุมแอดมิน</h1>
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
            <div class="max-w-7xl mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="glass-effect rounded-3xl p-6 stats-card border-t-4 border-green-500 animate__animated animate__fadeInDown modern-shadow">
                        <h3 class="text-xl font-bold gradient-text mb-6 flex items-center">
                            <i class="bi bi-check2-circle text-green-500 mr-2 text-xl"></i> สถิติอุปกรณ์
                        </h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-green-50 to-green-100 rounded-xl border border-green-200 card-hover">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-400 to-green-600 flex items-center justify-center text-white mr-4 shadow-lg">
                                        <i class="bi bi-check-circle text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-600 font-semibold mb-1">พร้อมใช้งาน</p>
                                        <p class="text-2xl font-bold text-gray-800"><?= $readyToBorrow ?> <span class="text-xs font-normal text-gray-500">รายการ</span></p>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-orange-50 to-orange-100 rounded-xl border border-orange-200 card-hover">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-orange-400 to-orange-600 flex items-center justify-center text-white mr-4 shadow-lg">
                                        <i class="bi bi-clock-history text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-600 font-semibold mb-1">ถูกยืมอยู่</p>
                                        <p class="text-2xl font-bold text-gray-800"><?= $currentlyBorrowed ?> <span class="text-xs font-normal text-gray-500">รายการ</span></p>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl border border-blue-200 card-hover">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-400 to-blue-600 flex items-center justify-center text-white mr-4 shadow-lg">
                                        <i class="bi bi-people text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-600 font-semibold mb-1">สมาชิกทั้งหมด</p>
                                        <p class="text-2xl font-bold text-gray-800"><?= $totalMembers ?> <span class="text-xs font-normal text-gray-500">คน</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="md:col-span-2 space-y-6">
                        <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInLeft modern-shadow">
                            <h3 class="text-xl font-bold gradient-text mb-6 flex items-center">
                                <i class="bi bi-graph-up text-purple-600 mr-2 text-xl"></i> สถิติการยืม 7 วันล่าสุด
                            </h3>
                            <canvas id="lineChart" height="120"></canvas>
                        </div>

                        <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInRight modern-shadow">
                            <h3 class="text-xl font-bold gradient-text mb-6 flex items-center">
                                <i class="bi bi-pie-chart text-purple-600 mr-2 text-xl"></i> สัดส่วนการยืมตามหมวดหมู่
                            </h3>
                            <div class="max-w-[300px] mx-auto">
                                <canvas id="pieChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Calendar Section -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <h3 class="text-2xl font-bold gradient-text mb-6 flex items-center">
                        <i class="bi bi-calendar3 text-purple-600 mr-3 text-2xl"></i> ปฏิทินการยืมอุปกรณ์
                    </h3>
                    <div id='calendar' class="bg-white rounded-xl p-4"></div>
                </div>
            </div>
        </div>
    </main>

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

        // เตรียมข้อมูลจาก PHP
        const categoryLabels = <?= json_encode(array_column($categoryStats, 'name')) ?>;
        const categoryData = <?= json_encode(array_column($categoryStats, 'total')) ?>;
        const dailyLabels = <?= json_encode(array_column($dailyStats, 'date')) ?>;
        const dailyData = <?= json_encode(array_column($dailyStats, 'count')) ?>;

        // --- 1. Line Chart ---
        const ctxLine = document.getElementById('lineChart').getContext('2d');
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: dailyLabels,
                datasets: [{
                    label: 'จำนวนการยืม',
                    data: dailyData,
                    borderColor: '#722ff9',
                    backgroundColor: 'rgba(114, 47, 249, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { plugins: { legend: { display: false } } }
        });

        // --- 2. Pie Chart ---
        const ctxPie = document.getElementById('pieChart').getContext('2d');
        new Chart(ctxPie, {
            type: 'pie',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryData,
                    backgroundColor: ['#722ff9', '#B8A2F9', '#DDD6FE', '#A78BFA', '#8B5CF6']
                }]
            }
        });

        // --- 3. FullCalendar (จาก Logic เดิมของคุณ) ---
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'th',
                events: 'fetch_borrowings.php', // ตรวจสอบว่าไฟล์นี้อยู่โฟลเดอร์เดียวกัน
                eventBackgroundColor: '#722ff9',
                eventBorderColor: '#B8A2F9',
                eventClick: function(info) {
                    Swal.fire({
                        title: 'รายละเอียดการยืม',
                        html: `
                            <div class="text-center">
                                <p class="mb-2"><strong>อุปกรณ์:</strong> ${info.event.title}</p>
                                <p class="mb-2"><strong>ผู้ยืม:</strong> ${info.event.extendedProps.username}</p>
                                <p class="mb-4"><strong>วันที่ยืม:</strong> ${info.event.start.toLocaleDateString('th-TH')}</p>
                                <img src="Uploads/${info.event.extendedProps.image}" class="mx-auto rounded-lg shadow-md max-w-[200px]">
                            </div>
                        `,
                        confirmButtonColor: '#722ff9'
                    });
                }
            });
            calendar.render();
        });
    </script>
</body>
</html>

