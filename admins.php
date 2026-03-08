<?php
session_start();
require_once 'config.php';

// 1. ตรวจสอบสิทธิ์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();

$alertScript = '';

// --- 2. LOGIC: AJAX FETCH MEMBERS (สำหรับแสดงตารางและค้นหา) ---
if (isset($_GET['ajax'])) {
    $search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%%";
    $role_filter = isset($_GET['role_filter']) ? $_GET['role_filter'] : "";
    if ($role_filter === 'member') {
        $role_filter = 'user';
    }

    $sql = "SELECT id, username, first_name, last_name, profile_image, role, created_at FROM users WHERE username LIKE :search";
    if ($role_filter != "") { $sql .= " AND role = :role"; }
    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':search', $search);
    if ($role_filter != "") $stmt->bindValue(':role', $role_filter);
    $stmt->execute();
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($members) > 0) {
        foreach ($members as $m) {
            $roleBadge = ($m['role'] == 'admin') 
                ? "<span class='px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-bold'><i class='bi bi-shield-lock-fill mr-1'></i> Admin</span>" 
                : "<span class='px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-bold'><i class='bi bi-person-fill mr-1'></i> Member</span>";
            
            // สร้าง avatar สำหรับสมาชิกแต่ละคน
            $memberAvatar = '';
            $memberName = '';
            
            if ($m['profile_image'] && $m['profile_image'] !== 'default.jpg') {
                $memberAvatar = "<img src='Uploads/profiles/" . htmlspecialchars($m['profile_image']) . "' class='w-full h-full rounded-xl object-cover'>";
            } else {
                $initials = strtoupper(substr($m['first_name'] ?: $m['username'], 0, 2));
                $memberAvatar = "<div class='w-full h-full rounded-xl bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm'>{$initials}</div>";
            }
            
            $memberName = ($m['first_name'] && $m['last_name']) 
                ? htmlspecialchars($m['first_name'] . ' ' . $m['last_name'])
                : htmlspecialchars($m['username']);
            
            echo "
            <tr class='hover:bg-gray-50/50 transition-colors border-b border-gray-100 last:border-0'>
                <td class='px-6 py-4'>
                    <div class='flex items-center'>
                        <div class='w-12 h-12 rounded-xl overflow-hidden bg-gray-100 border mr-3 flex-shrink-0'>
                            {$memberAvatar}
                        </div>
                        <div class='min-w-0 flex-1'>
                            <div class='font-bold text-gray-800 truncate'>{$memberName}</div>
                            <div class='text-xs text-gray-500 truncate'>@" . htmlspecialchars($m['username']) . "</div>
                            <div class='text-[10px] text-gray-400'>เข้าร่วมเมื่อ: " . date('d/m/Y', strtotime($m['created_at'])) . "</div>
                        </div>
                    </div>
                </td>
                <td class='px-6 py-4 text-center'>$roleBadge</td>
                <td class='px-6 py-4 text-right'>
                    <button onclick=\"openEditModal({$m['id']}, '" . htmlspecialchars($m['username']) . "', '{$m['role']}', '" . htmlspecialchars($m['first_name'] ?: '') . "', '" . htmlspecialchars($m['last_name'] ?: '') . "')\" class='p-2 text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all mx-1'>
                        <i class='bi bi-pencil-square text-lg'></i>
                    </button>";
            
            if ($m['id'] != $_SESSION['user_id']) {
                echo "<button onclick=\"confirmDelete({$m['id']})\" class='p-2 text-red-600 hover:bg-red-50 rounded-lg transition-all mx-1'>
                            <i class='bi bi-trash text-lg'></i>
                        </button>";
            }
            
            echo "</td></tr>";
        }
    } else {
        echo "<tr><td colspan='3' class='px-6 py-12 text-center'><div class='text-gray-400'><i class='bi bi-people text-5xl mb-2 block'></i> ไม่พบข้อมูลสมาชิกที่ต้องการ</div></td></tr>";
    }
    exit;
}

// --- 3. LOGIC: ADD MEMBER ---
if (isset($_POST['add_member'])) {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];
    $role = $_POST['role'];
    if ($role === 'member') {
        $role = 'user';
    }
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    
    // จัดการรูปภาพ Profile
    $profile_image = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $profile_image = time() . '.' . $extension;
        
        // สร้างโฟลเดอร์ Uploads/profiles ถ้ายังไม่มี
        if (!is_dir('Uploads/profiles')) {
            mkdir('Uploads/profiles', 0755, true);
        }
        
        move_uploaded_file($_FILES['profile_image']['tmp_name'], 'Uploads/profiles/' . $profile_image);
    }
    
    if (!empty($user) && !empty($pass)) {
        try {
            $hashedPass = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, first_name, last_name, profile_image) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user, $hashedPass, $role, $first_name, $last_name, $profile_image]);
            $alertScript = "Swal.fire('สำเร็จ', 'เพิ่มสมาชิกเรียบร้อยแล้ว', 'success');";
        } catch (PDOException $e) { 
            $alertScript = "Swal.fire('Error', 'ชื่อผู้ใช้นี้มีอยู่แล้ว', 'error');"; 
        }
    }
}

// --- 4. LOGIC: UPDATE MEMBER ---
if (isset($_POST['update_member'])) {
    $id = $_POST['user_id'];
    $user = trim($_POST['username']);
    $role = $_POST['role'];
    if ($role === 'member') {
        $role = 'user';
    }
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    
    // จัดการรูปภาพ Profile
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $profile_image = time() . '.' . $extension;
        
        // สร้างโฟลเดอร์ Uploads/profiles ถ้ายังไม่มี
        if (!is_dir('Uploads/profiles')) {
            mkdir('Uploads/profiles', 0755, true);
        }
        
        move_uploaded_file($_FILES['profile_image']['tmp_name'], 'Uploads/profiles/' . $profile_image);
        
        // อัปเดตพร้อมรูปภาพ
        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ?, first_name = ?, last_name = ?, profile_image = ? WHERE id = ?");
            $stmt->execute([$user, $pass, $role, $first_name, $last_name, $profile_image, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ?, first_name = ?, last_name = ?, profile_image = ? WHERE id = ?");
            $stmt->execute([$user, $role, $first_name, $last_name, $profile_image, $id]);
        }
    } else {
        // อัปเดตโดยไม่เปลี่ยนรูปภาพ
        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ?, first_name = ?, last_name = ? WHERE id = ?");
            $stmt->execute([$user, $pass, $role, $first_name, $last_name, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ?, first_name = ?, last_name = ? WHERE id = ?");
            $stmt->execute([$user, $role, $first_name, $last_name, $id]);
        }
    }
    $alertScript = "Swal.fire('สำเร็จ', 'อัปเดตข้อมูลเรียบร้อยแล้ว', 'success');";
}

// --- 5. LOGIC: DELETE MEMBER ---
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    if ($id != $_SESSION['user_id']) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        header("Location: admins.php"); exit;
    } else { $alertScript = "Swal.fire('ผิดพลาด', 'คุณไม่สามารถลบตัวเองได้', 'error');"; }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสมาชิก - Admin</title>
    
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
        <span class="font-bold text-lg">จัดการสมาชิก</span>
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
                    <h1 class="text-lg font-bold text-gray-800">จัดการสมาชิก</h1>
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
                            <h2 class="text-2xl font-bold gradient-text mb-2">จัดการสมาชิก</h2>
                            <p class="text-gray-600">จัดการผู้ใช้และสิทธิ์การเข้าถึงระบบ</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button onclick="toggleModal('addMemberModal')" class="modern-button text-white px-6 py-3 rounded-xl font-medium shadow-lg">
                                <i class="bi bi-person-plus mr-2"></i> เพิ่มสมาชิก
                            </button>
                        </div>
                    </div>

                    <!-- Search and Filter -->
                    <div class="bg-white/50 backdrop-blur rounded-xl p-4 flex flex-wrap items-center gap-4 mb-6">
                        <div class="relative flex-1 max-w-md">
                            <i class="bi bi-search absolute left-4 top-3 text-gray-400"></i>
                            <input type="text" id="memberSearch" placeholder="ค้นหาชื่อผู้ใช้..." 
                                class="w-full pl-11 pr-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all shadow-sm">
                        </div>
                        
                        <select id="roleFilter" onchange="loadMembers()" class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                            <option value="">ทุกสิทธิ์</option>
                            <option value="admin">Admin</option>
                            <option value="user">Member</option>
                        </select>
                    </div>
                </div>

                <!-- Members Table -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-white/50 backdrop-blur border-b border-gray-200">
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ข้อมูลสมาชิก</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">สิทธิ์</th>
                                    <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="membersTableBody" class="divide-y divide-gray-100">
                                <!-- Content will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Member Modal -->
    <div id="addMemberModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-purple-50 to-blue-50">
                <h3 class="text-xl font-bold gradient-text">เพิ่มสมาชิกใหม่</h3>
                <button onclick="toggleModal('addMemberModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data" class="p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่อผู้ใช้</label>
                        <input type="text" name="username" required placeholder="เช่น user123" 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่อจริง</label>
                            <input type="text" name="first_name" placeholder="กรอกชื่อจริง" 
                                class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">นามสกุล</label>
                            <input type="text" name="last_name" placeholder="กรอกนามสกุล" 
                                class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">รหัสผ่าน</label>
                        <input type="password" name="password" required placeholder="•••••••••" 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">สิทธิ์</label>
                        <select name="role" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                            <option value="">เลือกสิทธิ์</option>
                            <option value="user">Member</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">รูปภาพ Profile</label>
                        <div class="mt-2 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-purple-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <i class="bi bi-person-circle text-3xl text-gray-400"></i>
                                <div class="flex text-sm text-gray-600">
                                    <label for="addProfileImage" class="relative cursor-pointer bg-white rounded-md font-medium text-purple-600 hover:text-purple-500 focus-within:outline-none">
                                        <span>อัปโหลดรูปภาพ</span>
                                        <input id="addProfileImage" name="profile_image" type="file" accept="image/*" class="sr-only">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PNG, JPG, GIF สูงสุด 5MB</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="toggleModal('addMemberModal')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="add_member" class="flex-1 py-3 modern-button text-white font-bold rounded-xl">เพิ่มสมาชิก</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div id="editMemberModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-orange-50 to-red-50">
                <h3 class="text-xl font-bold text-gray-800">แก้ไขข้อมูลสมาชิก</h3>
                <button onclick="toggleModal('editMemberModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form id="editMemberForm" action="" method="POST" enctype="multipart/form-data" class="p-6">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่อผู้ใช้</label>
                        <input type="text" name="username" id="editUsername" required 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่อจริง</label>
                            <input type="text" name="first_name" id="editFirstName" placeholder="กรอกชื่อจริง" 
                                class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">นามสกุล</label>
                            <input type="text" name="last_name" id="editLastName" placeholder="กรอกนามสกุล" 
                                class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">รหัสผ่าน (ใสมใหม่ถ้าต้องการเปลี่ยน)</label>
                        <input type="password" name="password" placeholder="ปล่วยไว้ถ้าไม่ต้องการเปลี่ยน" 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">สิทธิ์</label>
                        <select name="role" id="editRole" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                            <option value="user">Member</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">รูปภาพ Profile (เปลี่ยนใหม่ถ้าต้องการ)</label>
                        <div class="mt-2 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-purple-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <i class="bi bi-person-circle text-3xl text-gray-400"></i>
                                <div class="flex text-sm text-gray-600">
                                    <label for="editProfileImage" class="relative cursor-pointer bg-white rounded-md font-medium text-purple-600 hover:text-purple-500 focus-within:outline-none">
                                        <span>เปลี่ยนรูปภาพ</span>
                                        <input id="editProfileImage" name="profile_image" type="file" accept="image/*" class="sr-only">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PNG, JPG, GIF สูงสุด 5MB</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="toggleModal('editMemberModal')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="update_member" class="flex-1 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-bold rounded-xl hover:from-green-600 transition-all">บันทึกการแก้ไข</button>
                </div>
            </form>
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

        // Modal Toggle
        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.toggle('hidden');
            }
        }

        // Load Members Data
        function loadMembers() {
            const search = $('#memberSearch').val();
            const role = $('#roleFilter').val();

            $.ajax({
                url: 'admins.php',
                type: 'GET',
                data: { 
                    search: search,
                    role_filter: role,
                    ajax: 1
                },
                success: function(data) {
                    $('#membersTableBody').html(data);
                }
            });
        }

        // Auto-load on input change
        $('#memberSearch').on('input', function() {
            loadMembers();
        });

        // Open Edit Modal
        function openEditModal(id, username, role, firstName, lastName) {
            $('#editUserId').val(id);
            $('#editUsername').val(username);
            $('#editRole').val(role);
            $('#editFirstName').val(firstName || '');
            $('#editLastName').val(lastName || '');
            toggleModal('editMemberModal');
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
                    window.location.href = `admins.php?delete_id=${id}`;
                }
            });
        }

        // Initialize
        $(document).ready(function() {
            loadMembers();
            <?php if ($alertScript): ?>
                <?= $alertScript ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>



