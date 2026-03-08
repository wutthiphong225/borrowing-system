<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$alertScript = '';

// --- LOGIC: ADVANCED AJAX SEARCH & FILTER ---
if (isset($_GET['ajax'])) {
    $search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%%";
    $category_id = isset($_GET['category_id']) ? $_GET['category_id'] : "";
    $status = isset($_GET['status']) ? $_GET['status'] : "";

    // สร้าง Query พื้นฐาน
    $sql = "SELECT e.*, c.name as category_name 
            FROM equipment e 
            LEFT JOIN categories c ON e.category_id = c.id 
            WHERE (e.name LIKE :search OR e.description LIKE :search)";
    
    // กรองตามหมวดหมู่
    if ($category_id != "") {
        $sql .= " AND e.category_id = :category_id";
    }

    // กรองตามสถานะ (เช็กจาก Quantity)
    if ($status == "available") {
        $sql .= " AND e.quantity > 0";
    } elseif ($status == "out_of_stock") {
        $sql .= " AND e.quantity <= 0";
    }

    $sql .= " ORDER BY e.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':search', $search);
    if ($category_id != "") $stmt->bindValue(':category_id', $category_id);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($results) > 0) {
        foreach ($results as $item) {
            $statusBadge = ($item['quantity'] > 0) 
                ? "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800'><i class='bi bi-check-circle-fill mr-1'></i> พร้อมใช้งาน ({$item['quantity']})</span>" 
                : "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800'><i class='bi bi-exclamation-triangle-fill mr-1'></i> ของหมด</span>";
            
            echo "
            <tr class='hover:bg-gray-50/50 transition-colors'>
                <td class='px-6 py-4'>
                    <div class='flex items-center'>
                        <div class='flex-shrink-0 h-12 w-12 border rounded-lg overflow-hidden bg-gray-100 shadow-sm'>
                            <img src='Uploads/".($item['image'] ?: 'default.jpg')."' class='h-full w-full object-cover'>
                        </div>
                        <div class='ml-4'>
                            <div class='text-sm font-bold text-gray-900'>".htmlspecialchars($item['name'])."</div>
                            <div class='text-xs text-gray-500'>ID: #".str_pad($item['id'], 5, '0', STR_PAD_LEFT)."</div>
                        </div>
                    </div>
                </td>
                <td class='px-6 py-4 text-center text-sm text-gray-600'>".htmlspecialchars($item['category_name'])."</td>
                <td class='px-6 py-4 text-center'>$statusBadge</td>
                <td class='px-6 py-4 text-right text-sm font-medium'>
                    <button onclick=\"toggleModal('editEquipModal{$item['id']}')\" class='text-indigo-600 hover:text-indigo-900 bg-indigo-50 p-2 rounded-lg mr-1 transition-all'>
                        <i class='bi bi-pencil-square'></i>
                    </button>
                    <button onclick=\"confirmDelete({$item['id']})\" class='text-red-600 hover:text-red-900 bg-red-50 p-2 rounded-lg transition-all'>
                        <i class='bi bi-trash'></i>
                    </button>
                </td>
            </tr>";
        }
    } else {
        echo "<tr><td colspan='4' class='px-6 py-12 text-center'><div class='text-gray-400'><i class='bi bi-inbox text-5xl mb-2 block'></i> ไม่พบข้อมูลอุปกรณ์ที่ต้องการ</div></td></tr>";
    }
    exit;
}

// --- LOGIC: ADD EQUIPMENT ---
if (isset($_POST['add_equipment'])) {
    $name = trim($_POST['name']);
    $category_id = $_POST['category_id'];
    $quantity = $_POST['quantity'];
    $description = "";

    $image_name = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time() . '.' . $extension;
        move_uploaded_file($_FILES['image']['tmp_name'], 'Uploads/' . $image_name);
    }

    $stmt = $pdo->prepare("INSERT INTO equipment (name, category_id, quantity, image, description) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$name, $category_id, $quantity, $image_name, $description])) {
        $alertScript = "Swal.fire('สำเร็จ', 'เพิ่มอุปกรณ์เรียบร้อยแล้ว', 'success');";
    }
}

// --- LOGIC: UPDATE EQUIPMENT ---
if (isset($_POST['update_equipment'])) {
    $id = $_POST['equipment_id'];
    $name = trim($_POST['name']);
    $category_id = $_POST['category_id'];
    $quantity = $_POST['quantity'];
    $description = trim($_POST['description']);

    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time() . '.' . $extension;
        move_uploaded_file($_FILES['image']['tmp_name'], 'Uploads/' . $image_name);
        
        $stmt = $pdo->prepare("UPDATE equipment SET name = ?, category_id = ?, quantity = ?, image = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $category_id, $quantity, $image_name, $description, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE equipment SET name = ?, category_id = ?, quantity = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $category_id, $quantity, $description, $id]);
    }
    $alertScript = "Swal.fire('สำเร็จ', 'แก้ไขข้อมูลเรียบร้อยแล้ว', 'success');";
}

// --- LOGIC: DELETE EQUIPMENT ---
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE equipment_id = ? AND status = 'borrowed'");
        $check->execute([$id]);
        
        if ($check->fetchColumn() > 0) {
            $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่สามารถลบได้เนื่องจากอุปกรณ์นี้ถูกยืมอยู่', 'error');";
        } else {
            $stmt = $pdo->prepare("DELETE FROM equipment WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: equipment.php");
            exit;
        }
    } catch (PDOException $e) {
        $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่สามารถลบข้อมูลได้', 'error');";
    }
}

// ดึงข้อมูลหมวดหมู่มาโชว์ใน Select
$categories = $pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);
// ดึงข้อมูลอุปกรณ์ทั้งหมด (สำหรับ Modal)
$all_equipment = $pdo->query("SELECT * FROM equipment")->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการอุปกรณ์ - Admin</title>
    
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
        <span class="font-bold text-lg">จัดการอุปกรณ์</span>
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
                    <h1 class="text-lg font-bold text-gray-800">จัดการอุปกรณ์</h1>
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
                            <h2 class="text-2xl font-bold gradient-text mb-2">คลังอุปกรณ์</h2>
                            <p class="text-gray-600">จัดการและตรวจสอบรายการอุปกรณ์ทั้งหมดในระบบ</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button onclick="toggleModal('addEquipModal')" class="modern-button text-white px-6 py-3 rounded-xl font-medium shadow-lg">
                                <i class="bi bi-plus-lg mr-2"></i> เพิ่มอุปกรณ์ใหม่
                            </button>
                        </div>
                    </div>

                    <!-- Search and Filter -->
                    <div class="bg-white/50 backdrop-blur rounded-xl p-4 flex flex-wrap items-center gap-4 mb-6">
                        <div class="relative flex-1 max-w-md">
                            <i class="bi bi-search absolute left-4 top-3 text-gray-400"></i>
                            <input type="text" id="equipSearch" placeholder="ค้นหาชื่ออุปกรณ์..." 
                                class="w-full pl-11 pr-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all shadow-sm">
                        </div>
                        
                        <select id="filterCategory" onchange="loadEquipment()" class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                            <option value="">ทุกหมวดหมู่</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="filterStatus" onchange="loadEquipment()" class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                            <option value="">ทุกสถานะ</option>
                            <option value="available">พร้อมใช้งาน</option>
                            <option value="out_of_stock">ของหมด</option>
                        </select>
                    </div>
                </div>

                <!-- Equipment Table -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-white/50 backdrop-blur border-b border-gray-200">
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ข้อมูลอุปกรณ์</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">หมวดหมู่</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">สถานะการคลัง</th>
                                    <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="equipTableBody" class="divide-y divide-gray-100">
                                <!-- Content will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Equipment Modal -->
    <div id="addEquipModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-purple-50 to-blue-50">
                <h3 class="text-xl font-bold gradient-text">เพิ่มอุปกรณ์เข้าคลัง</h3>
                <button onclick="toggleModal('addEquipModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data" class="p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่ออุปกรณ์</label>
                        <input type="text" name="name" required placeholder="เช่น Projector Epson EB-X06" 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">หมวดหมู่</label>
                        <select name="category_id" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                            <option value="">เลือกหมวดหมู่</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">จำนวนตั้นต้น</label>
                        <input type="number" name="quantity" value="1" min="1" required 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">รายละเอียด / หมายเหตุ</label>
                        <textarea name="description" rows="3" placeholder="ระบุรายละเอียดเพิ่มเติม..." 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all"></textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">รูปภาพอุปกรณ์</label>
                        <div class="mt-2 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-purple-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <i class="bi bi-image text-3xl text-gray-400"></i>
                                <div class="flex text-sm text-gray-600">
                                    <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-purple-600 hover:text-purple-500 focus-within:outline-none">
                                        <span>อัปโหลดไฟล์รูปภาพ</span>
                                        <input id="file-upload" name="image" type="file" accept="image/*" class="sr-only">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PNG, JPG, GIF สูงสูง 5MB</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex gap-4 mt-8">
                    <button type="button" onclick="toggleModal('addEquipModal')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="add_equipment" class="flex-1 py-3 modern-button text-white font-bold rounded-xl">เพิ่มอุปกรณ์</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Equipment Modals -->
    <?php foreach ($all_equipment as $item): ?>
    <div id="editEquipModal<?= $item['id'] ?>" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-orange-50 to-red-50">
                <h3 class="text-xl font-bold text-gray-800">แก้ไขอุปกรณ์</h3>
                <button onclick="toggleModal('editEquipModal<?= $item['id'] ?>')" class="text-gray-400 hover:text-gray-600">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data" class="p-8">
                <input type="hidden" name="equipment_id" value="<?= $item['id'] ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">ชื่ออุปกรณ์</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">หมวดหมู่</label>
                        <select name="category_id" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($cat['id'] == $item['category_id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">จำนวน</label>
                        <input type="number" name="quantity" value="<?= $item['quantity'] ?>" min="0" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">คำอธิบาย</label>
                        <textarea name="description" class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20"><?= htmlspecialchars($item['description']) ?></textarea>
                    </div>
                    <div class="md:col-span-2 text-center">
                        <img src="Uploads/<?= $item['image'] ?>" class="w-24 h-24 mx-auto rounded-xl object-cover mb-3 border">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">เปลี่ยนรูปภาพ (ถ้ามี)</label>
                        <input type="file" name="image" accept="image/*" class="text-sm w-full p-2 bg-gray-50 border border-gray-200 rounded-xl">
                    </div>
                </div>
                <div class="flex gap-4 mt-8">
                    <button type="button" onclick="toggleModal('editEquipModal<?= $item['id'] ?>')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="update_equipment" class="flex-1 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-bold rounded-xl hover:from-green-600 transition-all">บันทึกการแก้ไข</button>
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

        // Load Equipment Data
        function loadEquipment() {
            const query = $('#equipSearch').val();
            const category = $('#filterCategory').val();
            const status = $('#filterStatus').val();

            $.ajax({
                url: 'equipment.php',
                type: 'GET',
                data: { 
                    search: query,
                    category_id: category,
                    status: status,
                    ajax: 1
                },
                success: function(data) {
                    $('#equipTableBody').html(data);
                }
            });
        }

        // Auto-load on input change
        $('#equipSearch').on('input', function() {
            loadEquipment();
        });

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
                    window.location.href = `equipment.php?delete_id=${id}`;
                }
            });
        }

        // Initialize
        $(document).ready(function() {
            loadEquipment();
            <?php if ($alertScript): ?>
                <?= $alertScript ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>


