<?php
// ฟังก์ชันสำหรับเช็กหน้าปัจจุบันเพื่อทำ Active Menu
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside id="sidebar" class="sidebar-gradient w-64 text-white hidden md:block shrink-0 animate__animated animate__fadeInLeft">
    <!-- Sidebar Toggle Button -->
    <div class="sidebar-toggle">
        <i class="bi bi-chevron-left text-xs"></i>
    </div>
    
    <div class="p-6 text-center border-b border-gray-700">
        <div class="sidebar-header">
            <i class="logo-icon bi bi-box-seam text-3xl text-purple-400 mb-3 block"></i>
            <h2 class="text-2xl font-bold tracking-tight sidebar-text">ระบบยืม-คืน</h2>
            <p class="text-[10px] text-gray-400 uppercase tracking-widest mt-1 sidebar-text">Management System</p>
        </div>
    </div>
    
    <nav class="mt-6 px-4 space-y-1">
        <a href="admin_dashboard.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'admin_dashboard.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-speedometer2 mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">แดชบอร์ด</span>
        </a>

        <a href="approval_dashboard.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'approval_dashboard.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-check-circle mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">อนุมัติคำร้อง</span>
        </a>

        <a href="manage_borrowings.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'manage_borrowings.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-clipboard-data mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">จัดการการยืม-คืน</span>
        </a>

        <a href="qr_scan.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'qr_scan.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-qr-code-scan mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">สแกน QR Code</span>
        </a>

        <a href="equipment.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'equipment.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-box-seam mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">จัดการอุปกรณ์</span>
        </a>

        <a href="categorie.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'categorie.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-tags mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">จัดการหมวดหมู่</span>
        </a>

        <a href="admins.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'admins.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-person-badge mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">จัดการสมาชิก</span>
        </a>

        <a href="report_borrowing.php" class="sidebar-item flex items-center p-3 rounded-xl transition-all <?= ($current_page == 'report_borrowing.php') ? 'active' : '' ?>">
            <i class="sidebar-icon bi bi-file-earmark-bar-graph mr-3 text-lg"></i> 
            <span class="sidebar-text font-medium">รายงานการยืม-คืน</span>
        </a>
    </nav>

    <div class="absolute bottom-0 w-64 p-4 border-t border-gray-700 bg-gray-800/50">
        <a href="logout.php" class="flex items-center p-3 text-red-400 hover:bg-red-500/20 rounded-xl transition-all font-bold">
            <i class="sidebar-icon bi bi-box-arrow-right mr-3 text-lg"></i> 
            <span class="sidebar-text">ออกจากระบบ</span>
        </a>
    </div>
</aside>
