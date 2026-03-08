<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์ผู้ใช้
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: login.php');
    exit;
}

$alertScript = '';

// ดึงข้อมูลผู้ใช้ปัจจุบัน
$stmt = $pdo->prepare("SELECT username, student_id, first_name, last_name, profile_image, created_at FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

// อัปเดตข้อมูลผู้ใช้
if (isset($_POST['update_profile'])) {
    $username = trim($_POST['username']);
    $student_id = trim($_POST['student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // ตรวจสอบว่ากรอกข้อมูลนักศึกษาอย่างน้อย 1 ฟิลด์
    if (empty($student_id) && empty($first_name) && empty($last_name)) {
        $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'กรุณากรอกข้อมูลนักศึกษาอย่างน้อย 1 ฟิลด์ (รหัสนักศึกษา, ชื่อจริง หรือนามสกุล)', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
    } else {
        // ตรวจสอบรหัสผ่านปัจจุบันเฉพาะเมื่อต้องการเปลี่ยนรหัสผ่าน
        if (!empty($new_password)) {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!password_verify($current_password, $user['password'])) {
                $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'รหัสผ่านปัจจุบันไม่ถูกต้อง', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
            } else {
                // ตรวจสอบความถูกต้องของรหัสผ่านใหม่
                if (strlen($new_password) < 6) {
                    $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
                } elseif ($new_password !== $confirm_password) {
                    $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
                } else {
                    // อัปเดตข้อมูลทั้งหมดรวมรหัสผ่าน
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, student_id = ?, first_name = ?, last_name = ?, password = ? WHERE id = ?");
                    $stmt->execute([$username, $student_id, $first_name, $last_name, $hashed_password, $_SESSION['user_id']]);
                    
                    // อัปเดต session username
                    $_SESSION['username'] = $username;
                    
                    $alertScript = "$.alert({ title: 'สำเร็จ', content: 'อัปเดตโปรไฟล์เรียบร้อยแล้ว', type: 'green', theme: 'modern', icon: 'bi bi-check-circle-fill' });";
                }
            }
        } else {
            // อัปเดตเฉพาะข้อมูลส่วนตัว (ไม่ต้องรหัสผ่าน)
            $stmt = $pdo->prepare("UPDATE users SET username = ?, student_id = ?, first_name = ?, last_name = ? WHERE id = ?");
            $stmt->execute([$username, $student_id, $first_name, $last_name, $_SESSION['user_id']]);
            
            // อัปเดต session username
            $_SESSION['username'] = $username;
            
            $alertScript = "$.alert({ title: 'สำเร็จ', content: 'อัปเดตโปรไฟล์เรียบร้อยแล้ว', type: 'green', theme: 'modern', icon: 'bi bi-check-circle-fill' });";
        }
    }
}

// จัดการการอัปโหลดรูปภาพ
if (isset($_POST['upload_image'])) {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        $file_info = pathinfo($_FILES['profile_image']['name']);
        $file_extension = strtolower($file_info['extension']);
        
        if (in_array($file_extension, $allowed_types)) {
            // สร้างชื่อไฟล์ใหม่
            $new_filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $upload_path = 'Uploads/profiles/' . $new_filename;
            
            // สร้างโฟลเดอร์ถ้าไม่มี
            if (!is_dir('Uploads/profiles')) {
                mkdir('Uploads/profiles', 0777, true);
            }
            
            // อัปโหลดไฟล์
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                // อัปเดตชื่อไฟล์ในฐานข้อมูล
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$new_filename, $_SESSION['user_id']]);
                
                $alertScript = "$.alert({ title: 'สำเร็จ', content: 'อัปโหลดรูปภาพเรียบร้อยแล้ว', type: 'green', theme: 'modern', icon: 'bi bi-check-circle-fill' });";
            } else {
                $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'ไม่สามารถอัปโหลดรูปภาพได้', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
            }
        } else {
            $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'อนุญาตเฉพาะไฟล์รูปภาพ (JPG, JPEG, PNG, GIF)', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
        }
    } else {
        $alertScript = "$.alert({ title: 'ข้อผิดพลาด', content: 'กรุณาเลือกไฟล์รูปภาพ', type: 'red', theme: 'modern', icon: 'bi bi-x-circle-fill' });";
    }
}

// ดึงข้อมูลใหม่หลังการอัปเดต
$stmt = $pdo->prepare("SELECT username, student_id, first_name, last_name, profile_image, created_at FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

// ดึงประวัติการยืมของผู้ใช้
$stmt = $pdo->prepare("
    SELECT b.*, e.name as equipment_name, e.image as equipment_image,
           CASE 
               WHEN b.approval_status = 'pending' THEN 'รออนุมัติ'
               WHEN b.approval_status = 'approved' AND b.status = 'borrowed' THEN 'กำลังยืม'
               WHEN b.approval_status = 'rejected' THEN 'ถูกปฏิเสธ'
               WHEN b.status = 'returned' THEN 'คืนแล้ว'
               ELSE 'ไม่ทราบสถานะ'
           END as status_text
    FROM borrowings b 
    JOIN equipment e ON b.equipment_id = e.id 
    WHERE b.user_id = ? 
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$recentBorrowings = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ผู้ใช้ - Equipment Borrowing</title>
    
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
        .profile-avatar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }
        .profile-avatar img {
            object-fit: cover;
            width: 100%;
            height: 100%;
        }
        .upload-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            cursor: pointer;
        }
        .profile-avatar:hover .upload-overlay {
            opacity: 1;
        }
    </style>
</head>
<body class="text-gray-800 h-screen flex overflow-hidden">

    <!-- Mobile Header -->
    <div class="md:hidden fixed w-full bg-[#722ff9] text-white z-50 flex justify-between items-center p-4 shadow-md">
        <span class="font-bold text-lg">โปรไฟล์ผู้ใช้</span>
        <button id="mobile-menu-btn" class="focus:outline-none">
            <i class="bi bi-list text-2xl"></i>
        </button>
    </div>

    <!-- Sidebar -->
    <?php include 'sidebar_user.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full relative overflow-hidden pt-16 md:pt-0">
        <!-- Top Bar -->
        <header class="glass-effect z-10 px-8 py-6 flex justify-between items-center border-b border-white/20">
            <div>
                <h1 class="text-3xl font-bold gradient-text animate__animated animate__fadeInDown">โปรไฟล์ผู้ใช้</h1>
                <p class="text-gray-600 text-sm mt-2">จัดการข้อมูลส่วนตัวของคุณ</p>
            </div>
            <div class="hidden md:block">
                <span class="glass-effect px-6 py-3 rounded-full text-sm font-medium flex items-center space-x-2">
                    <i class="bi bi-person-circle text-purple-600"></i> 
                    <span class="gradient-text">User Account</span>
                </span>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 md:p-10">
            <div class="max-w-4xl mx-auto">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Profile Card -->
                    <div class="lg:col-span-1">
                        <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInLeft modern-shadow">
                            <div class="text-center">
                                <!-- Profile Image -->
                                <div class="profile-avatar w-24 h-24 rounded-full mx-auto mb-4 text-3xl relative">
                                    <?php if ($currentUser['profile_image'] && $currentUser['profile_image'] !== 'default.jpg'): ?>
                                        <img src="Uploads/profiles/<?php echo htmlspecialchars($currentUser['profile_image']); ?>" 
                                             alt="Profile" class="w-full h-full">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($currentUser['first_name'] ?: $currentUser['username'], 0, 2)); ?>
                                    <?php endif; ?>
                                    
                                    <!-- Upload Overlay -->
                                    <form method="POST" enctype="multipart/form-data" class="upload-overlay">
                                        <label for="profile_image" class="cursor-pointer">
                                            <i class="bi bi-camera-fill text-white text-2xl"></i>
                                            <input type="hidden" name="upload_image" value="1">
                                            <input type="file" id="profile_image" name="profile_image" 
                                                   class="hidden" accept="image/*" onchange="this.form.submit()">
                                        </label>
                                    </form>
                                </div>
                                
                                <h3 class="text-xl font-bold gradient-text mb-2">
                                    <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name'] ?: $currentUser['username']); ?>
                                </h3>
                                
                                <?php if ($currentUser['student_id']): ?>
                                    <p class="text-sm text-purple-600 font-semibold mb-2">รหัสนักศึกษา: <?php echo htmlspecialchars($currentUser['student_id']); ?></p>
                                <?php endif; ?>
                                
                                <p class="text-sm text-gray-600 mb-4">สมาชิกทั่วไป</p>
                                
                                <div class="text-left space-y-3">
                                    <div class="flex items-center text-sm text-gray-600">
                                        <i class="bi bi-person-badge mr-3 text-purple-600"></i>
                                        <span>ชื่อผู้ใช้: <?php echo htmlspecialchars($currentUser['username']); ?></span>
                                    </div>
                                    <div class="flex items-center text-sm text-gray-600">
                                        <i class="bi bi-calendar-event mr-3 text-purple-600"></i>
                                        <span>เข้าร่วมเมื่อ: <?php echo date('d/m/Y', strtotime($currentUser['created_at'])); ?></span>
                                    </div>
                                    <div class="flex items-center text-sm text-gray-600">
                                        <i class="bi bi-shield-check mr-3 text-purple-600"></i>
                                        <span>สถานะ: ใช้งานได้</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Profile Form -->
                    <div class="lg:col-span-2">
                        <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInRight modern-shadow">
                            <h3 class="text-xl font-bold gradient-text mb-6 flex items-center">
                                <i class="bi bi-gear text-purple-600 mr-2"></i> แก้ไขข้อมูลส่วนตัว
                            </h3>
                            
                            <form method="POST" class="space-y-6">
                                <!-- Student Information -->
                                <div class="border-t pt-6">
                                    <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                        <i class="bi bi-person-badge text-purple-600 mr-2"></i> ข้อมูลนักศึกษา
                                    </h4>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">รหัสนักศึกษา</label>
                                            <input type="text" name="student_id" value="<?php echo htmlspecialchars($currentUser['student_id'] ?? ''); ?>" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all"
                                                   placeholder="เช่น 640123456">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อผู้ใช้</label>
                                            <input type="text" name="username" value="<?php echo htmlspecialchars($currentUser['username']); ?>" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all"
                                                   required>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อจริง</label>
                                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($currentUser['first_name'] ?? ''); ?>" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all"
                                                   placeholder="กรอกชื่อจริง">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">นามสกุล</label>
                                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($currentUser['last_name'] ?? ''); ?>" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all"
                                                   placeholder="กรอกนามสกุล">
                                        </div>
                                    </div>
                                </div>

                                <!-- Password Section -->
                                <div class="border-t pt-6">
                                    <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                        <i class="bi bi-lock text-purple-600 mr-2"></i> เปลี่ยนรหัสผ่าน
                                    </h4>
                                    <p class="text-sm text-gray-600 mb-4">ปล่อยว่างหากไม่ต้องการเปลี่ยนรหัสผ่าน</p>
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">รหัสผ่านปัจจุบัน <span class="text-red-500">*</span></label>
                                            <input type="password" name="current_password" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all"
                                                   placeholder="กรอกเฉพาะเมื่อต้องการเปลี่ยนรหัสผ่าน">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">รหัสผ่านใหม่</label>
                                            <input type="password" name="new_password" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all"
                                                   placeholder="อย่างน้อย 6 ตัวอักษร">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">ยืนยันรหัสผ่านใหม่</label>
                                            <input type="password" name="confirm_password" 
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none transition-all">
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end space-x-4">
                                    <a href="user_dashboard.php" class="px-6 py-3 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-all">
                                        ยกเลิก
                                    </a>
                                    <button type="submit" name="update_profile" 
                                            class="modern-button text-white px-6 py-3 rounded-xl font-semibold">
                                        <i class="bi bi-check-circle mr-2"></i> บันทึกการเปลี่ยนแปลง
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="mt-8">
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <h3 class="text-xl font-bold gradient-text mb-6 flex items-center">
                            <i class="bi bi-clock-history text-purple-600 mr-2"></i> กิจกรรมล่าสุด
                        </h3>
                        
                        <?php if (count($recentBorrowings) > 0): ?>
                            <div class="space-y-3">
                                <?php foreach ($recentBorrowings as $borrowing): ?>
                                    <div class="flex items-center justify-between p-4 bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl border border-gray-200">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 rounded-lg overflow-hidden mr-3">
                                                <img src="Uploads/<?php echo htmlspecialchars($borrowing['equipment_image'] ?: 'default.jpg'); ?>" 
                                                     class="w-full h-full object-cover" alt="<?php echo htmlspecialchars($borrowing['equipment_name']); ?>">
                                            </div>
                                            <div>
                                                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($borrowing['equipment_name']); ?></p>
                                                <p class="text-xs text-gray-600"><?php echo $borrowing['quantity']; ?> ชิ้น • <?php echo date('d/m/Y', strtotime($borrowing['borrow_date'])); ?></p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs px-3 py-1 rounded-full font-semibold
                                                <?php 
                                                if ($borrowing['status_text'] === 'รออนุมัติ') echo 'bg-blue-100 text-blue-700';
                                                elseif ($borrowing['status_text'] === 'กำลังยืม') echo 'bg-orange-100 text-orange-700';
                                                elseif ($borrowing['status_text'] === 'ถูกปฏิเสธ') echo 'bg-red-100 text-red-700';
                                                else echo 'bg-green-100 text-green-700';
                                                ?>">
                                                <?php echo $borrowing['status_text']; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <i class="bi bi-inbox text-4xl text-gray-300 mb-3"></i>
                                <p class="text-gray-500">ยังไม่มีกิจกรรม</p>
                            </div>
                        <?php endif; ?>
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

        $(document).ready(function() {
            <?php if ($alertScript): ?>
                <?php echo $alertScript; ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>

