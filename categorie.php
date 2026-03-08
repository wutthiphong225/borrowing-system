<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$alertScript = '';

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();

// --- LOGIC: เพิ่มหมวดหมู่ ---
if (isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->execute([$name]);
            $alertScript = "Swal.fire('สำเร็จ', 'เพิ่มหมวดหมู่เรียบร้อยแล้ว', 'success');";
        } catch (PDOException $e) {
            $alertScript = "Swal.fire('ข้อผิดพลาด', 'ชื่อหมวดหมู่นี้อาจมีอยู่แล้ว', 'error');";
        }
    }
}

// --- LOGIC: แก้ไขหมวดหมู่ ---
if (isset($_POST['update_category'])) {
    $id = $_POST['category_id'];
    $name = trim($_POST['name']);
    if (!empty($name)) {
        $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        $alertScript = "Swal.fire('สำเร็จ', 'อัปเดตข้อมูลเรียบร้อยแล้ว', 'success');";
    }
}

// --- LOGIC: ลบหมวดหมู่ ---
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    // ตรวจสอบก่อนว่ามีอุปกรณ์ในหมวดหมู่นี้หรือไม่
    $check = $pdo->prepare("SELECT COUNT(*) FROM equipment WHERE category_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        $alertScript = "Swal.fire('ไม่สามารถลบได้', 'มีอุปกรณ์ที่ใช้งานหมวดหมู่นี้อยู่ กรุณาลบอุปกรณ์ออกก่อน', 'warning');";
    } else {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $alertScript = "Swal.fire('ลบแล้ว', 'ลบหมวดหมู่เรียบร้อยแล้ว', 'success');";
    }
}

// ดึงข้อมูลหมวดหมู่ทั้งหมด
$categories = $pdo->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการหมวดหมู่ - Admin</title>
    
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
        <span class="font-bold text-lg">จัดการหมวดหมู่</span>
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
                    <h1 class="text-lg font-bold text-gray-800">จัดการหมวดหมู่</h1>
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
                            <h2 class="text-2xl font-bold gradient-text mb-2">หมวดหมู่อุปกรณ์</h2>
                            <p class="text-gray-600">จัดการและจัดระเบียบหมวดหมู่อุปกรณ์ทั้งหมดในระบบ</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button onclick="toggleModal('addCategoryModal')" class="modern-button text-white px-6 py-3 rounded-xl font-medium shadow-lg">
                                <i class="bi bi-plus-lg mr-2"></i> เพิ่มหมวดหมู่
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Categories Table -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-white/50 backdrop-blur border-b border-gray-200">
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ลำดับ</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ชื่อหมวดหมู่</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">จำนวนอุปกรณ์</th>
                                    <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (count($categories) > 0): ?>
                                    <?php foreach ($categories as $index => $cat): ?>
                                        <?php 
                                        // นับจำนวนอุปกรณ์ในหมวดหมู่นี้
                                        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM equipment WHERE category_id = ?");
                                        $countStmt->execute([$cat['id']]);
                                        $equipmentCount = $countStmt->fetchColumn();
                                        ?>
                                        <tr class="hover:bg-gray-50/50 transition-colors">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="w-8 h-8 rounded-full bg-gradient-to-r from-purple-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm mr-3">
                                                        <?= $index + 1 ?>
                                                    </div>
                                                    <span class="text-sm text-gray-600">#<?= str_pad($cat['id'], 5, '0', STR_PAD_LEFT) ?></span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-r from-blue-500 to-cyan-500 flex items-center justify-center text-white mr-3">
                                                        <i class="bi bi-tags text-lg"></i>
                                                    </div>
                                                    <span class="font-medium text-gray-800"><?= htmlspecialchars($cat['name']) ?></span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <i class="bi bi-box mr-1"></i> <?= $equipmentCount ?> รายการ
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <button onclick="toggleModal('editCategoryModal<?= $cat['id'] ?>')" class="text-blue-600 hover:text-blue-900 bg-blue-50 p-2 rounded-lg mr-1 transition-all">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <button onclick="confirmDelete(<?= $cat['id'] ?>)" class="text-red-600 hover:text-red-900 bg-red-50 p-2 rounded-lg transition-all">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-12 text-center">
                                            <div class="text-gray-400">
                                                <i class="bi bi-tags text-5xl mb-2 block"></i>
                                                <p class="text-lg">ยังไม่มีข้อมูลหมวดหมู่</p>
                                                <p class="text-sm">คลิกปุ่ม "เพิ่มหมวดหมู่" เพื่อเริ่มต้น</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Category Modal -->
    <div id="addCategoryModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-purple-50 to-blue-50">
                <h3 class="text-xl font-bold gradient-text">เพิ่มหมวดหมู่ใหม่</h3>
                <button onclick="toggleModal('addCategoryModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form action="" method="POST" class="p-6">
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่อหมวดหมู่</label>
                    <input type="text" name="name" required placeholder="เช่น คอมพิวเตอร์" 
                        class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="toggleModal('addCategoryModal')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="add_category" class="flex-1 py-3 modern-button text-white font-bold rounded-xl">เพิ่มหมวดหมู่</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Category Modals -->
    <?php foreach ($categories as $cat): ?>
    <div id="editCategoryModal<?= $cat['id'] ?>" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-orange-50 to-red-50">
                <h3 class="text-xl font-bold text-gray-800">แก้ไขหมวดหมู่</h3>
                <button onclick="toggleModal('editCategoryModal<?= $cat['id'] ?>')" class="text-gray-400 hover:text-gray-600">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่อหมวดหมู่</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($cat['name']) ?>" required 
                        class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="toggleModal('editCategoryModal<?= $cat['id'] ?>')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="update_category" class="flex-1 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-bold rounded-xl hover:from-green-600 transition-all">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
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

        // Modal Toggle
        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.toggle('hidden');
            }
        }

        // Confirm Delete
        function confirmDelete(id) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: "ข้อมูลนี้จะถูกลบออกจากระบบอย่างถาวร",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#722ff9',
                cancelButtonColor: '#d33',
                confirmButtonText: 'ใช่, ลบเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `categorie.php?delete_id=${id}`;
                }
            });
        }

        // Initialize
        $(document).ready(function() {
            <?php if ($alertScript): ?>
                <?= $alertScript ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>


