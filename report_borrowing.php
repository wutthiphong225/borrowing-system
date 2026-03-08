<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();

// --- LOGIC: AJAX FETCH REPORT ---
if (isset($_GET['ajax'])) {
    $search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%%";
    $date_start = isset($_GET['date_start']) ? $_GET['date_start'] : "";
    $date_end = isset($_GET['date_end']) ? $_GET['date_end'] : "";
    $status = isset($_GET['status']) ? $_GET['status'] : "";

    $sql = "SELECT b.*, e.name as equip_name, u.username as borrower_name, u.first_name as borrower_firstname, u.last_name as borrower_lastname,
                   e.image as equip_image, e.quantity as equip_quantity
            FROM borrowings b
            JOIN equipment e ON b.equipment_id = e.id
            JOIN users u ON b.user_id = u.id
            WHERE (e.name LIKE :search OR u.username LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    
    if ($date_start != "") $sql .= " AND b.borrow_date >= :date_start";
    if ($date_end != "") $sql .= " AND b.borrow_date <= :date_end";
    if ($status != "") $sql .= " AND b.status = :status";

    $sql .= " ORDER BY b.borrow_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':search', $search);
    if ($date_start != "") $stmt->bindValue(':date_start', $date_start);
    if ($date_end != "") $stmt->bindValue(':date_end', $date_end);
    if ($status != "") $stmt->bindValue(':status', $status);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($results) > 0) {
        foreach ($results as $row) {
            $statusBadge = '';
            $statusIcon = '';
            $statusColor = '';
            
            if ($row['status'] == 'borrowed') {
                $statusBadge = 'bg-amber-100 text-amber-700';
                $statusIcon = 'bi-clock-history';
                $statusText = 'กำลังยืม';
            } elseif ($row['status'] == 'returned') {
                $statusBadge = 'bg-emerald-100 text-emerald-700';
                $statusIcon = 'bi-check-circle-fill';
                $statusText = 'คืนแล้ว';
            } else {
                $statusBadge = 'bg-gray-100 text-gray-700';
                $statusIcon = 'bi-hourglass-split';
                $statusText = 'รอดอนุมัติ';
            }
            
            // สร้าง avatar สำหรับผู้ยืมแต่ละคน
            $borrowerAvatar = '';
            $borrowerName = '';
            
            if (isset($row['borrower_firstname']) && isset($row['borrower_lastname']) && $row['borrower_firstname'] && $row['borrower_lastname']) {
                $borrowerName = htmlspecialchars($row['borrower_firstname'] . ' ' . $row['borrower_lastname']);
            } else {
                $borrowerName = htmlspecialchars($row['borrower_name']);
            }
            
            $initials = strtoupper(substr($row['borrower_firstname'] ?: $row['borrower_name'], 0, 1));
            $borrowerAvatar = "<div class='w-full h-full rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold'>{$initials}</div>";
            
            echo "
            <tr class='hover:bg-gray-50/50 transition-colors border-b border-gray-100 last:border-0'>
                <td class='px-6 py-4'>
                    <div class='flex items-center'>
                        <div class='w-12 h-12 rounded-xl overflow-hidden bg-gray-100 border mr-3'>
                            <img src='Uploads/".($row['equip_image'] ?: 'default.jpg')."' class='w-full h-full object-cover'>
                        </div>
                        <div>
                            <div class='font-bold text-gray-800'>".htmlspecialchars($row['equip_name'])."</div>
                            <div class='text-xs text-gray-500'>จำนวน: {$row['equip_quantity']}</div>
                        </div>
                    </div>
                </td>
                <td class='px-6 py-4'>
                    <div class='flex items-center'>
                        <div class='w-8 h-8 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold mr-2'>
                            ".strtoupper(substr($row['borrower_firstname'] ?: $row['borrower_name'], 0, 1))."
                        </div>
                        <div>
                            <div class='font-medium text-gray-800'>{$borrowerName}</div>
                            <div class='text-xs text-gray-500'>@" . htmlspecialchars($row['borrower_name']) . "</div>
                        </div>
                    </div>
                </td>
                <td class='px-6 py-4 text-sm text-gray-600'>
                    <div class='flex items-center'>
                        <i class='bi bi-calendar-event mr-2 text-purple-500'></i>
                        <span>".date('d/m/Y', strtotime($row['borrow_date']))."</span>
                    </div>
                </td>
                <td class='px-6 py-4 text-sm text-gray-600'>
                    <div class='flex items-center'>
                        <i class='bi bi-calendar-check mr-2 text-green-500'></i>
                        <span>".($row['return_date'] ? date('d/m/Y', strtotime($row['return_date'])) : '-')."</span>
                    </div>
                </td>
                <td class='px-6 py-4 text-center'>
                    <span class='inline-flex items-center px-3 py-1 rounded-full text-xs font-bold $statusBadge'>
                        <i class='bi $statusIcon mr-1'></i> $statusText
                    </span>
                </td>
            </tr>";
        }
    } else {
        echo "<tr><td colspan='5' class='px-6 py-12 text-center'><div class='text-gray-400'><i class='bi bi-file-earmark-bar-graph text-5xl mb-2 block'></i> ไม่พบข้อมูลรายงานที่ต้องการ</div></td></tr>";
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการยืม-คืน - Admin</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
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
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">รายงานการยืม-คืน</span>
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
                    <h1 class="text-lg font-bold text-gray-800">รายงานการยืม-คืน</h1>
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
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInDown modern-shadow mb-8">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                        <div>
                            <h2 class="text-2xl font-bold gradient-text mb-2">รายงานการยืม-คืน</h2>
                            <p class="text-gray-600">ตรวจสอบและจัดการประวัติการยืม-คืนอุปกรณ์ทั้งหมด</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button onclick="exportReport()" class="bg-gradient-to-r from-green-500 to-green-600 text-white px-6 py-3 rounded-xl font-medium shadow-lg hover:from-green-600 transition-all">
                                <i class="bi bi-download mr-2"></i> ส่งออกรายงาน
                            </button>
                        </div>
                    </div>

                    <!-- Search and Filter -->
                    <div class="bg-white/50 backdrop-blur rounded-xl p-4 flex flex-wrap items-center gap-4 mb-6">
                        <div class="relative flex-1 max-w-md">
                            <i class="bi bi-search absolute left-4 top-3 text-gray-400"></i>
                            <input type="text" id="reportSearch" placeholder="ค้นหาชื่ออุปกรณ์หรือผู้ยืม..." 
                                class="w-full pl-11 pr-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all shadow-sm">
                        </div>
                        
                        <input type="date" id="dateStart" onchange="loadReport()" placeholder="วันที่เริ่มต้น" 
                            class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                        
                        <input type="date" id="dateEnd" onchange="loadReport()" placeholder="วันที่สิ้นสุด" 
                            class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                        
                        <select id="statusFilter" onchange="loadReport()" class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                            <option value="">ทุกสถานะ</option>
                            <option value="borrowed">กำลังยืม</option>
                            <option value="returned">คืนแล้ว</option>
                        </select>
                    </div>
                </div>

                <!-- Report Table -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-white/50 backdrop-blur border-b border-gray-200">
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">อุปกรณ์</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ผู้ยืม</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">วันที่ยืม</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">วันที่คืน</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody" class="divide-y divide-gray-100">
                                <!-- Content will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">สรวมทั้งหมด</h3>
                                <p class="text-gray-600 text-sm">การยืมทั้งหมด</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white">
                                <i class="bi bi-graph-up text-xl"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-800" id="totalCount">0</div>
                    </div>
                    
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">กำลังยืม</h3>
                                <p class="text-gray-600 text-sm">อุปกรณ์ที่ยังไม่ได้คืน</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-amber-500 to-orange-600 flex items-center justify-center text-white">
                                <i class="bi bi-clock-history text-xl"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-amber-600" id="borrowedCount">0</div>
                    </div>
                    
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">คืนแล้ว</h3>
                                <p class="text-gray-600 text-sm">อุปกรณ์ที่คืนเสร็จแล้ว</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-500 to-emerald-600 flex items-center justify-center text-white">
                                <i class="bi bi-check-circle text-xl"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-green-600" id="returnedCount">0</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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

        // Load Report Data
        function loadReport() {
            const search = $('#reportSearch').val();
            const dateStart = $('#dateStart').val();
            const dateEnd = $('#dateEnd').val();
            const status = $('#statusFilter').val();

            $.ajax({
                url: 'report_borrowing.php',
                type: 'GET',
                data: { 
                    search: search,
                    date_start: dateStart,
                    date_end: dateEnd,
                    status: status,
                    ajax: 1
                },
                success: function(data) {
                    $('#reportTableBody').html(data);
                    updateStatistics();
                }
            });
        }

        // Update Statistics
        function updateStatistics() {
            const rows = $('#reportTableBody tr');
            let borrowed = 0;
            let returned = 0;
            
            rows.each(function() {
                const statusText = $(this).find('td:last-child span').text();
                if (statusText.includes('กำลังยืม')) borrowed++;
                if (statusText.includes('คืนแล้ว')) returned++;
            });
            
            $('#totalCount').text(rows.length);
            $('#borrowedCount').text(borrowed);
            $('#returnedCount').text(returned);
        }

        // Export Report
        function exportReport() {
            Swal.fire({
                title: 'ส่งออกรายงาน',
                html: `
                    <div class="text-center">
                        <p class="mb-4">ระบบจะส่งออกรายงานในรูปแบ CSV</p>
                        <div class="bg-gray-100 rounded-lg p-4">
                            <p class="text-sm text-gray-600">ข้อมูลจะถูกรวมถึง:</p>
                            <ul class="text-left text-sm text-gray-600">
                                <li>• ชื่ออุปกรณ์</li>
                                <li>• ชื่อผู้ยืม</li>
                                <li>• วันที่ยืม/คืน</li>
                                <li>• สถานะปัจจุบัน</li>
                            </ul>
                        </div>
                    </div>
                `,
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#722ff9',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ส่งออก',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    // สร้าง CSV และส่งออก
                    const csvContent = generateCSV();
                    downloadCSV(csvContent, 'borrowing_report_' + new Date().toISOString().split('T')[0] + '.csv');
                    Swal.fire('สำเร็จ', 'ส่งออกรายงานสำเร็จแล้ว', 'success');
                }
            });
        }

        // Generate CSV Content
        function generateCSV() {
            let csv = 'อุปกรณ์,ผู้ยืม,วันที่ยืม,วันที่คืน,สถานะ\n';
            
            $('#reportTableBody tr').each(function() {
                const cells = $(this).find('td');
                const equipName = $(cells[0]).find('.font-bold').text().trim();
                const borrowerName = $(cells[1]).find('.font-medium').text().trim();
                const borrowDate = $(cells[2]).find('span').text().trim();
                const returnDate = $(cells[3]).find('span').text().trim();
                const status = $(cells[4]).find('span').text().trim();
                
                csv += `"${equipName}","${borrowerName}","${borrowDate}","${returnDate}","${status}"\n`;
            });
            
            return csv;
        }

        // Download CSV
        function downloadCSV(content, filename) {
            const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Initialize
        $(document).ready(function() {
            loadReport();
        });
    </script>
</body>
</html>


