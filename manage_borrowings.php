<?php
session_start();
require_once 'config.php';

// ตรวจสอบสิทธิ์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ดึงข้อมูลโปรไฟล์ Admin
$stmt = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$_SESSION['user_id']]);
$adminProfile = $stmt->fetch();

$alertScript = '';

// --- LOGIC: GET DELETED BORROWINGS ---
if (isset($_GET['get_deleted'])) {
    $sql = "SELECT db.*, e.name as equip_name, u.username as borrower_name, u.first_name as borrower_firstname, u.last_name as borrower_lastname,
                   admin.username as deleted_by_username, admin.first_name as deleted_by_firstname, admin.last_name as deleted_by_lastname
            FROM deleted_borrowings db
            LEFT JOIN equipment e ON db.equipment_id = e.id
            LEFT JOIN users u ON db.user_id = u.id
            LEFT JOIN users admin ON db.deleted_by = admin.id
            ORDER BY db.deleted_at DESC
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $deletedBorrowings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($deletedBorrowings) > 0) {
        foreach ($deletedBorrowings as $row) {
            // สถานะการยืม
            $statusBadge = '';
            $statusIcon = '';
            $statusText = '';
            
            if ($row['status'] == 'borrowed') {
                $statusBadge = 'bg-amber-100 text-amber-700';
                $statusIcon = 'bi-clock-history';
                $statusText = 'กำลังยืม';
            } elseif ($row['status'] == 'returned') {
                $statusBadge = 'bg-emerald-100 text-emerald-700';
                $statusIcon = 'bi-check-circle-fill';
                $statusText = 'คืนแล้ว';
            }
            
            // สถานะการอนุมัติ
            $approvalBadge = '';
            if ($row['approval_status'] == 'pending') {
                $approvalBadge = '<span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium">รออนุมัติ</span>';
            } elseif ($row['approval_status'] == 'approved') {
                $approvalBadge = '<span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">อนุมัติแล้ว</span>';
            } elseif ($row['approval_status'] == 'rejected') {
                $approvalBadge = '<span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">ถูกปฏิเสธ</span>';
            }
            
            // ชื่อผู้ลบ
            $deletedByName = ($row['deleted_by_firstname'] && $row['deleted_by_lastname']) 
                ? htmlspecialchars($row['deleted_by_firstname'] . ' ' . $row['deleted_by_lastname'])
                : htmlspecialchars($row['deleted_by_username']);
            
            echo "
            <tr class='hover:bg-gray-50/50 transition-colors border-b border-gray-100 last:border-0'>
                <td class='px-4 py-3'>
                    <div class='font-medium text-gray-800 truncate'>".htmlspecialchars($row['equip_name'])."</div>
                    <div class='text-xs text-gray-500'>ID: #" . str_pad($row['original_id'], 5, '0', STR_PAD_LEFT) . "</div>
                </td>
                <td class='px-4 py-3'>
                    <div class='font-medium text-gray-800 truncate'>".htmlspecialchars($row['borrower_firstname'] . ' ' . $row['borrower_lastname'])."</div>
                    <div class='text-xs text-gray-500'>@" . htmlspecialchars($row['borrower_name']) . "</div>
                </td>
                <td class='px-4 py-3 text-sm text-gray-600'>
                    ".date('d/m/Y H:i', strtotime($row['deleted_at']))."
                </td>
                <td class='px-4 py-3 text-sm text-gray-600'>
                    {$deletedByName}
                </td>
                <td class='px-4 py-3 text-center'>
                    <button onclick=\"restoreBorrowing({$row['original_id']})\" class='px-3 py-1 bg-green-100 text-green-700 rounded-lg text-xs font-medium hover:bg-green-200 transition-colors' title='คืนข้อมูล'>
                        <i class='bi bi-arrow-counterclockwise mr-1'></i> คืน
                    </button>
                </td>
            </tr>";
        }
    } else {
        echo "<tr><td colspan='6' class='px-4 py-8 text-center text-gray-500'>ไม่มีข้อมูลที่ถูกลบ</td></tr>";
    }
    exit;
}

// --- LOGIC: GET BORROWING DETAILS ---
if (isset($_GET['get_borrowing']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    $stmt = $pdo->prepare("
        SELECT b.*, e.name as equip_name, u.username as borrower_name, u.first_name as borrower_firstname, u.last_name as borrower_lastname
        FROM borrowings b
        JOIN equipment e ON b.equipment_id = e.id
        JOIN users u ON b.user_id = u.id
        WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $borrowing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($borrowing) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'borrowing' => $borrowing]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการยืม']);
    }
    exit;
}

// --- LOGIC: AJAX FETCH BORROWINGS ---
if (isset($_GET['ajax'])) {
    $search = isset($_GET['search']) ? "%" . $_GET['search'] . "%" : "%%";
    $date_start = isset($_GET['date_start']) ? $_GET['date_start'] : "";
    $date_end = isset($_GET['date_end']) ? $_GET['date_end'] : "";
    $status = isset($_GET['status']) ? $_GET['status'] : "";
    $approval_status = isset($_GET['approval_status']) ? $_GET['approval_status'] : "";

    $sql = "SELECT b.*, e.name as equip_name, e.image as equip_image, e.quantity as equip_quantity,
                   u.username as borrower_name, u.first_name as borrower_firstname, u.last_name as borrower_lastname,
                   u.profile_image as borrower_profile_image
            FROM borrowings b
            JOIN equipment e ON b.equipment_id = e.id
            JOIN users u ON b.user_id = u.id
            WHERE (e.name LIKE :search OR u.username LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    
    if ($date_start != "") $sql .= " AND b.borrow_date >= :date_start";
    if ($date_end != "") $sql .= " AND b.borrow_date <= :date_end";
    if ($status != "") $sql .= " AND b.status = :status";
    if ($approval_status != "") $sql .= " AND b.approval_status = :approval_status";

    $sql .= " ORDER BY b.borrow_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':search', $search);
    if ($date_start != "") $stmt->bindValue(':date_start', $date_start);
    if ($date_end != "") $stmt->bindValue(':date_end', $date_end);
    if ($status != "") $stmt->bindValue(':status', $status);
    if ($approval_status != "") $stmt->bindValue(':approval_status', $approval_status);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($results) > 0) {
        foreach ($results as $row) {
            // สถานะการยืม
            $statusBadge = '';
            $statusIcon = '';
            $statusColor = '';
            
            if ($row['status'] == 'borrowed') {
                if ($row['approval_status'] == 'pending') {
                    $statusBadge = 'bg-gray-100 text-gray-700';
                    $statusIcon = 'bi-hourglass-split';
                    $statusText = 'รออนุมัติ';
                } elseif ($row['approval_status'] == 'rejected') {
                    $statusBadge = 'bg-red-100 text-red-700';
                    $statusIcon = 'bi-x-circle-fill';
                    $statusText = 'ถูกปฏิเสธ';
                } elseif ($row['approval_status'] == 'approved') {
                    if (isset($row['pickup_confirmed']) && $row['pickup_confirmed'] == 1) {
                        $statusBadge = 'bg-blue-100 text-blue-700';
                        $statusIcon = 'bi-box-arrow-in-down';
                        $statusText = 'รับแล้ว';
                    } else {
                        $statusBadge = 'bg-amber-100 text-amber-700';
                        $statusIcon = 'bi-clock-history';
                        $statusText = 'รอรับ';
                    }
                }
            } elseif ($row['status'] == 'returned') {
                $statusBadge = 'bg-emerald-100 text-emerald-700';
                $statusIcon = 'bi-check-circle-fill';
                $statusText = 'คืนแล้ว';
            }
            
            // สถานะการอนุมัติ
            $approvalBadge = '';
            if ($row['approval_status'] == 'pending') {
                $approvalBadge = '<span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium">รออนุมัติ</span>';
            } elseif ($row['approval_status'] == 'approved') {
                $approvalBadge = '<span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">อนุมัติแล้ว</span>';
            } elseif ($row['approval_status'] == 'rejected') {
                $approvalBadge = '<span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">ถูกปฏิเสธ</span>';
            }
            
            // สร้าง avatar สำหรับผู้ยืม
            $borrowerAvatar = '';
            $borrowerName = '';
            
            if (isset($row['borrower_firstname']) && isset($row['borrower_lastname']) && $row['borrower_firstname'] && $row['borrower_lastname']) {
                $borrowerName = htmlspecialchars($row['borrower_firstname'] . ' ' . $row['borrower_lastname']);
            } else {
                $borrowerName = htmlspecialchars($row['borrower_name']);
            }
            
            $initials = strtoupper(substr($row['borrower_firstname'] ?: $row['borrower_name'], 0, 1));
            
            if ($row['borrower_profile_image'] && $row['borrower_profile_image'] !== 'default.jpg') {
                $borrowerAvatar = "<img src='Uploads/profiles/" . htmlspecialchars($row['borrower_profile_image']) . "' class='w-full h-full rounded-full object-cover'>";
            } else {
                $borrowerAvatar = "<div class='w-full h-full rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold'>{$initials}</div>";
            }
            
            echo "
            <tr class='hover:bg-gray-50/50 transition-colors border-b border-gray-100 last:border-0'>
                <td class='px-6 py-4'>
                    <div class='flex items-center'>
                        <div class='w-12 h-12 rounded-xl overflow-hidden bg-gray-100 border mr-3 flex-shrink-0'>
                            <img src='Uploads/".($row['equip_image'] ?: 'default.jpg')."' class='w-full h-full object-cover'>
                        </div>
                        <div class='min-w-0 flex-1'>
                            <div class='font-bold text-gray-800 truncate'>".htmlspecialchars($row['equip_name'])."</div>
                            <div class='text-xs text-gray-500'>จำนวน: {$row['equip_quantity']}</div>
                            <div class='text-xs text-gray-400'>ID: #" . str_pad($row['id'], 5, '0', STR_PAD_LEFT) . "</div>
                        </div>
                    </div>
                </td>
                <td class='px-6 py-4'>
                    <div class='flex items-center'>
                        <div class='w-10 h-10 rounded-full overflow-hidden bg-gray-100 border mr-3 flex-shrink-0'>
                            {$borrowerAvatar}
                        </div>
                        <div class='min-w-0 flex-1'>
                            <div class='font-medium text-gray-800 truncate'>{$borrowerName}</div>
                            <div class='text-xs text-gray-500 truncate'>@" . htmlspecialchars($row['borrower_name']) . "</div>
                        </div>
                    </div>
                </td>
                <td class='px-6 py-4 text-center'>
                    {$approvalBadge}
                </td>
                <td class='px-6 py-4 text-sm text-gray-600'>
                    <div class='flex items-center'>
                        <i class='bi bi-calendar-event mr-2 text-purple-500'></i>
                        <span>".date('d/m/Y H:i', strtotime($row['borrow_date']))."</span>
                    </div>
                </td>
                <td class='px-6 py-4 text-sm text-gray-600'>
                    <div class='flex items-center'>
                        <i class='bi bi-calendar-check mr-2 text-green-500'></i>
                        <span>".($row['return_date'] ? date('d/m/Y H:i', strtotime($row['return_date'])) : '-')."</span>
                    </div>
                </td>
                <td class='px-6 py-4 text-center'>
                    <span class='inline-flex items-center px-3 py-1 rounded-full text-xs font-bold $statusBadge'>
                        <i class='bi $statusIcon mr-1'></i> $statusText
                    </span>
                </td>
                <td class='px-6 py-4 text-right'>
                    <button onclick=\"openEditModal({$row['id']})\" class='p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-all mx-1' title='แก้ไข'>
                        <i class='bi bi-pencil-square text-lg'></i>
                    </button>";
            
            if ($row['status'] == 'borrowed' && $row['approval_status'] == 'approved') {
                echo "<button onclick=\"confirmReturn({$row['id']})\" class='p-2 text-green-600 hover:bg-green-50 rounded-lg transition-all mx-1' title='คืนอุปกรณ์'>
                            <i class='bi bi-arrow-counterclockwise text-lg'></i>
                        </button>";
            }
            
            if ($row['status'] == 'borrowed' && ($row['approval_status'] == 'pending' || $row['approval_status'] == 'rejected')) {
                echo "<button onclick=\"confirmDelete({$row['id']})\" class='p-2 text-red-600 hover:bg-red-50 rounded-lg transition-all mx-1' title='ลบคำร้อง'>
                            <i class='bi bi-trash text-lg'></i>
                        </button>";
            }
            
            echo "</td></tr>";
        }
    } else {
        echo "<tr><td colspan='7' class='px-6 py-12 text-center'><div class='text-gray-400'><i class='bi bi-clipboard-data text-5xl mb-2 block'></i> ไม่พบข้อมูลการยืมที่ต้องการ</div></td></tr>";
    }
    exit;
}

// --- LOGIC: UPDATE BORROWING ---
if (isset($_POST['update_borrowing'])) {
    $id = $_POST['borrowing_id'];
    $borrow_date = $_POST['borrow_date'];
    $return_date = !empty($_POST['return_date']) ? $_POST['return_date'] : null;
    $status = $_POST['status'];
    $approval_status = $_POST['approval_status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE borrowings SET borrow_date = ?, return_date = ?, status = ?, approval_status = ? WHERE id = ?");
        $stmt->execute([$borrow_date, $return_date, $status, $approval_status, $id]);
        $alertScript = "Swal.fire('สำเร็จ', 'อัปเดตข้อมูลการยืมเรียบร้อยแล้ว', 'success');";
    } catch (PDOException $e) {
        $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่สามารถอัปเดตข้อมูลได้: " . $e->getMessage() . "', 'error');";
    }
}

// --- LOGIC: RETURN EQUIPMENT ---
if (isset($_POST['return_equipment'])) {
    $id = $_POST['borrowing_id'];
    
    try {
        $pdo->beginTransaction();
        
        // ดึงข้อมูลการยืม
        $stmt = $pdo->prepare("SELECT equipment_id FROM borrowings WHERE id = ?");
        $stmt->execute([$id]);
        $borrowing = $stmt->fetch();
        
        // อัปเดตสถานะการยืม
        $stmt = $pdo->prepare("UPDATE borrowings SET status = 'returned', return_date = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        
        // เพิ่มจำนวนอุปกรณ์กลับคลัง
        $stmt = $pdo->prepare("UPDATE equipment SET quantity = quantity + 1 WHERE id = ?");
        $stmt->execute([$borrowing['equipment_id']]);
        
        $pdo->commit();
        $alertScript = "Swal.fire('สำเร็จ', 'คืนอุปกรณ์เรียบร้อยแล้ว', 'success');";
    } catch (Exception $e) {
        $pdo->rollBack();
        $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่สามารถคืนอุปกรณ์ได้: " . $e->getMessage() . "', 'error');";
    }
}

// --- LOGIC: DELETE BORROWING ---
if (isset($_POST['delete_borrowing'])) {
    $id = $_POST['borrowing_id'];
    
    try {
        $pdo->beginTransaction();
        
        // ดึงข้อมูลการยืม
        $stmt = $pdo->prepare("SELECT equipment_id, status FROM borrowings WHERE id = ?");
        $stmt->execute([$id]);
        $borrowing = $stmt->fetch();
        
        // ถ้ายังไม่ได้คืน ให้เพิ่มจำนวนอุปกรณ์กลับ
        if ($borrowing['status'] == 'borrowed') {
            $stmt = $pdo->prepare("UPDATE equipment SET quantity = quantity + 1 WHERE id = ?");
            $stmt->execute([$borrowing['equipment_id']]);
        }
        
        // บันทึกข้อมูลการยืมไว้ในตารางถังขยะก่อนลบ
        $stmt = $pdo->prepare("
            INSERT INTO deleted_borrowings 
            (original_id, equipment_id, user_id, borrow_date, return_date, status, approval_status, pickup_confirmed, pickup_time, approved_by, approved_at, deleted_at, deleted_by)
            SELECT id, equipment_id, user_id, borrow_date, return_date, status, approval_status, pickup_confirmed, pickup_time, approved_by, approved_at, NOW(), ?
            FROM borrowings 
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $id]);
        
        // ลบข้อมูลการยืม
        $stmt = $pdo->prepare("DELETE FROM borrowings WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        $alertScript = "Swal.fire('สำเร็จ', 'ลบข้อมูลการยืมเรียบร้อยแล้ว', 'success');";
    } catch (Exception $e) {
        $pdo->rollBack();
        $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่สามารถลบข้อมูลได้: " . $e->getMessage() . "', 'error');";
    }
}

// --- LOGIC: RESTORE BORROWING ---
if (isset($_POST['restore_borrowing'])) {
    $id = $_POST['deleted_id'];
    
    try {
        $pdo->beginTransaction();
        
        // ดึงข้อมูลจากถังขยะ
        $stmt = $pdo->prepare("SELECT * FROM deleted_borrowings WHERE original_id = ?");
        $stmt->execute([$id]);
        $deletedBorrowing = $stmt->fetch();
        
        if ($deletedBorrowing) {
            // ตรวจสอบว่ามีข้อมูลซ้ำในตารางหลักหรือไม่
            $checkStmt = $pdo->prepare("SELECT id FROM borrowings WHERE id = ?");
            $checkStmt->execute([$id]);
            $exists = $checkStmt->fetch();
            
            if (!$exists) {
                // คืนข้อมูลกลับตารางหลัก
                $stmt = $pdo->prepare("
                    INSERT INTO borrowings 
                    (id, equipment_id, user_id, borrow_date, return_date, status, approval_status, pickup_confirmed, pickup_time, approved_by, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $deletedBorrowing['original_id'],
                    $deletedBorrowing['equipment_id'],
                    $deletedBorrowing['user_id'],
                    $deletedBorrowing['borrow_date'],
                    $deletedBorrowing['return_date'],
                    $deletedBorrowing['status'],
                    $deletedBorrowing['approval_status'],
                    $deletedBorrowing['pickup_confirmed'],
                    $deletedBorrowing['pickup_time'],
                    $deletedBorrowing['approved_by'],
                    $deletedBorrowing['approved_at']
                ]);
                
                // ถ้าสถานะเป็น borrowed ให้ลดจำนวนอุปกรณ์
                if ($deletedBorrowing['status'] == 'borrowed') {
                    $stmt = $pdo->prepare("UPDATE equipment SET quantity = quantity - 1 WHERE id = ?");
                    $stmt->execute([$deletedBorrowing['equipment_id']]);
                }
                
                // ลบข้อมูลจากถังขยะ
                $stmt = $pdo->prepare("DELETE FROM deleted_borrowings WHERE original_id = ?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                $alertScript = "Swal.fire('สำเร็จ', 'คืนข้อมูลการยืมเรียบร้อยแล้ว', 'success');";
            } else {
                $alertScript = "Swal.fire('ข้อผิดพลาด', 'ข้อมูลนี้มีอยู่แล้วในระบบ', 'error');";
            }
        } else {
            $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่พบข้อมูลที่ต้องการคืน', 'error');";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $alertScript = "Swal.fire('ข้อผิดพลาด', 'ไม่สามารถคืนข้อมูลได้: " . $e->getMessage() . "', 'error');";
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการการยืม-คืน - Admin</title>
    
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
        <span class="font-bold text-lg">จัดการการยืม-คืน</span>
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
                    <h1 class="text-lg font-bold text-gray-800">จัดการการยืม-คืน</h1>
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
                            <h2 class="text-2xl font-bold gradient-text mb-2">จัดการการยืม-คืน</h2>
                            <p class="text-gray-600">แก้ไข อนุมัติ และจัดการข้อมูลการยืม-คืนอุปกรณ์</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button onclick="showTrashModal()" class="bg-gradient-to-r from-red-500 to-red-600 text-white px-6 py-3 rounded-xl font-medium shadow-lg hover:from-red-600 transition-all">
                                <i class="bi bi-trash3 mr-2"></i> ถังขยะ
                            </button>
                        </div>
                    </div>

                    <!-- Search and Filter -->
                    <div class="bg-white/50 backdrop-blur rounded-xl p-4 flex flex-wrap items-center gap-4 mb-6">
                        <div class="relative flex-1 max-w-md">
                            <i class="bi bi-search absolute left-4 top-3 text-gray-400"></i>
                            <input type="text" id="borrowingSearch" placeholder="ค้นหาชื่ออุปกรณ์หรือผู้ยืม..." 
                                class="w-full pl-11 pr-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all shadow-sm">
                        </div>
                        
                        <input type="date" id="dateStart" onchange="loadBorrowings()" placeholder="วันที่เริ่มต้น" 
                            class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                        
                        <input type="date" id="dateEnd" onchange="loadBorrowings()" placeholder="วันที่สิ้นสุด" 
                            class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                        
                        <select id="statusFilter" onchange="loadBorrowings()" class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                            <option value="">ทุกสถานะ</option>
                            <option value="borrowed">กำลังยืม</option>
                            <option value="returned">คืนแล้ว</option>
                        </select>
                        
                        <select id="approvalFilter" onchange="loadBorrowings()" class="px-4 py-3 bg-white/80 backdrop-blur border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20 shadow-sm text-gray-600">
                            <option value="">ทุกสถานะอนุมัติ</option>
                            <option value="pending">รออนุมัติ</option>
                            <option value="approved">อนุมัติแล้ว</option>
                            <option value="rejected">ถูกปฏิเสธ</option>
                        </select>
                    </div>
                </div>

                <!-- Borrowings Table -->
                <div class="glass-effect rounded-3xl p-8 animate__animated animate__fadeInUp modern-shadow">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-white/50 backdrop-blur border-b border-gray-200">
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">อุปกรณ์</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ผู้ยืม</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">อนุมัติ</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">วันที่ยืม</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">วันที่คืน</th>
                                    <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">สถานะ</th>
                                    <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="borrowingsTableBody" class="divide-y divide-gray-100">
                                <!-- Content will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mt-8">
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">ทั้งหมด</h3>
                                <p class="text-gray-600 text-sm">การยืมทั้งหมด</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white">
                                <i class="bi bi-clipboard-data text-xl"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-gray-800" id="totalCount">0</div>
                    </div>
                    
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">รออนุมัติ</h3>
                                <p class="text-gray-600 text-sm">คำร้องที่รอดำเนินการ</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-yellow-500 to-orange-600 flex items-center justify-center text-white">
                                <i class="bi bi-hourglass-split text-xl"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-bold text-yellow-600" id="pendingCount">0</div>
                    </div>
                    
                    <div class="glass-effect rounded-3xl p-6 animate__animated animate__fadeInUp modern-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">กำลังยืม</h3>
                                <p class="text-gray-600 text-sm">อุปกรณ์ที่ยังไม่ได้คืน</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-amber-500 to-orange-600 flex items-center justify-center text-white">
                                <i class="bi bi-box-arrow-in-down text-xl"></i>
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

    <!-- Edit Borrowing Modal -->
    <div id="editBorrowingModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-blue-50 to-purple-50">
                <h3 class="text-xl font-bold gradient-text">แก้ไขข้อมูลการยืม</h3>
                <button onclick="toggleModal('editBorrowingModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form id="editBorrowingForm" action="" method="POST" class="p-6">
                <input type="hidden" name="borrowing_id" id="editBorrowingId">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">วันที่ยืม</label>
                        <input type="datetime-local" name="borrow_date" id="editBorrowDate" required 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">วันที่คืน (ถ้าคืนแล้ว)</label>
                        <input type="datetime-local" name="return_date" id="editReturnDate" 
                            class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">สถานะการยืม</label>
                        <select name="status" id="editStatus" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                            <option value="borrowed">กำลังยืม</option>
                            <option value="returned">คืนแล้ว</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">สถานะการอนุมัติ</label>
                        <select name="approval_status" id="editApprovalStatus" required class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-purple-500/20">
                            <option value="pending">รออนุมัติ</option>
                            <option value="approved">อนุมัติแล้ว</option>
                            <option value="rejected">ถูกปฏิเสธ</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="toggleModal('editBorrowingModal')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="update_borrowing" class="flex-1 py-3 modern-button text-white font-bold rounded-xl">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Return Equipment Modal -->
    <div id="returnModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-md animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-green-50 to-emerald-50">
                <h3 class="text-xl font-bold text-green-800">คืนอุปกรณ์</h3>
                <button onclick="toggleModal('returnModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <form id="returnForm" action="" method="POST" class="p-6">
                <input type="hidden" name="borrowing_id" id="returnBorrowingId">
                <div class="mb-6">
                    <p class="text-gray-700 mb-4">คุณต้องการคืนอุปกรณ์นี้ใช่หรือไม่?</p>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                        <p class="text-sm text-yellow-800">
                            <i class="bi bi-info-circle mr-2"></i>
                            ระบบจะทำการเพิ่มจำนวนอุปกรณ์กลับคลังอัตโนมัติ
                        </p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="toggleModal('returnModal')" class="flex-1 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">ยกเลิก</button>
                    <button type="submit" name="return_equipment" class="flex-1 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-bold rounded-xl hover:from-green-600 transition-all">ยืนยันการคืน</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Trash Modal -->
    <div id="trashModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-4xl animate__animated animate__zoomIn animate__faster">
            <div class="p-6 border-b flex justify-between items-center bg-gradient-to-r from-red-50 to-orange-50">
                <h3 class="text-xl font-bold text-red-800">ถังขยะ - ข้อมูลที่ถูกลบ</h3>
                <button onclick="toggleModal('trashModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="bi bi-x-lg text-xl"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-lg font-semibold text-gray-800">รายการที่ถูกลบทั้งหมด</h4>
                        <button onclick="loadDeletedBorrowings()" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            <i class="bi bi-arrow-clockwise mr-1"></i> รีเฟรช
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200">
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase">อุปกรณ์</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase">ผู้ยืม</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase">วันที่ลบ</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase">ผู้ลบ</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="deletedBorrowingsTableBody" class="divide-y divide-gray-100">
                            <!-- Content will be loaded via AJAX -->
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-between items-center mt-6">
                    <p class="text-sm text-gray-600">
                        <i class="bi bi-info-circle mr-1"></i>
                        คุณสามารถคืนข้อมูลที่ถูกลบได้ภายใน 30 วัน
                    </p>
                    <button onclick="toggleModal('trashModal')" class="px-6 py-3 bg-gray-200 text-gray-700 font-medium rounded-xl hover:bg-gray-300 transition-all">
                        ปิด
                    </button>
                </div>
            </div>
        </div>
    </div>

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

        // Load Borrowings Data
        function loadBorrowings() {
            const search = $('#borrowingSearch').val();
            const dateStart = $('#dateStart').val();
            const dateEnd = $('#dateEnd').val();
            const status = $('#statusFilter').val();
            const approvalStatus = $('#approvalFilter').val();

            $.ajax({
                url: 'manage_borrowings.php',
                type: 'GET',
                data: { 
                    search: search,
                    date_start: dateStart,
                    date_end: dateEnd,
                    status: status,
                    approval_status: approvalStatus,
                    ajax: 1
                },
                success: function(data) {
                    $('#borrowingsTableBody').html(data);
                    updateStatistics();
                }
            });
        }

        // Update Statistics
        function updateStatistics() {
            const rows = $('#borrowingsTableBody tr');
            let pending = 0;
            let borrowed = 0;
            let returned = 0;
            let total = 0;
            
            // นับเฉพาะแถวที่มีข้อมูลจริง (ไม่ใช่แถวที่แสดงข้อความว่าง)
            rows.each(function() {
                // ตรวจสอบว่าแถวนี้มีข้อมูลจริงหรือไม่ (ไม่ใช่แถวที่แสดง "ไม่พบข้อมูล")
                const hasData = $(this).find('td').length > 1 || 
                               ($(this).find('td').length === 1 && !$(this).find('td').text().includes('ไม่พบข้อมูล'));
                
                if (hasData) {
                    total++;
                    const statusText = $(this).find('td:eq(5) span').text();
                    const approvalText = $(this).find('td:eq(2) span').text();
                    
                    if (approvalText.includes('รออนุมัติ')) pending++;
                    if (statusText.includes('กำลังยืม') || statusText.includes('รอรับ') || statusText.includes('รับแล้ว')) borrowed++;
                    if (statusText.includes('คืนแล้ว')) returned++;
                }
            });
            
            $('#totalCount').text(total);
            $('#pendingCount').text(pending);
            $('#borrowedCount').text(borrowed);
            $('#returnedCount').text(returned);
        }

        // Open Edit Modal
        function openEditModal(id) {
            // ดึงข้อมูลการยืม
            $.ajax({
                url: 'manage_borrowings.php',
                type: 'GET',
                data: { 
                    get_borrowing: 1,
                    id: id
                },
                success: function(data) {
                    if (data.success) {
                        $('#editBorrowingId').val(data.borrowing.id);
                        $('#editBorrowDate').val(data.borrowing.borrow_date.replace(' ', 'T'));
                        $('#editReturnDate').val(data.borrowing.return_date ? data.borrowing.return_date.replace(' ', 'T') : '');
                        $('#editStatus').val(data.borrowing.status);
                        $('#editApprovalStatus').val(data.borrowing.approval_status);
                        toggleModal('editBorrowingModal');
                    } else {
                        Swal.fire('ข้อผิดพลาด', 'ไม่สามารถดึงข้อมูลได้', 'error');
                    }
                },
                error: function() {
                    Swal.fire('ข้อผิดพลาด', 'เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                }
            });
        }

        // Confirm Return
        function confirmReturn(id) {
            $('#returnBorrowingId').val(id);
            toggleModal('returnModal');
        }

        // Confirm Delete
        function confirmDelete(id) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: "ข้อมูลการยืมนี้จะถูกลบออกจากระบบอย่างถาวร",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#722ff9',
                cancelButtonColor: '#d33',
                confirmButtonText: 'ใช่, ลบเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = $('<form>', {
                        method: 'POST',
                        action: 'manage_borrowings.php'
                    });
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'delete_borrowing',
                        value: '1'
                    }));
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'borrowing_id',
                        value: id
                    }));
                    form.appendTo('body').submit();
                }
            });
        }

        // Show Trash Modal
        function showTrashModal() {
            toggleModal('trashModal');
            loadDeletedBorrowings();
        }

        // Load Deleted Borrowings
        function loadDeletedBorrowings() {
            $.ajax({
                url: 'manage_borrowings.php',
                type: 'GET',
                data: { 
                    get_deleted: 1
                },
                success: function(data) {
                    $('#deletedBorrowingsTableBody').html(data);
                },
                error: function() {
                    $('#deletedBorrowingsTableBody').html(
                        '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">ไม่สามารถโหลดข้อมูลได้</td></tr>'
                    );
                }
            });
        }

        // Restore Borrowing
        function restoreBorrowing(id) {
            Swal.fire({
                title: 'ยืนยันการคืน?',
                text: "คุณต้องการคืนข้อมูลการยืมนี้กลับมาใช่หรือไม่?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ใช่, คืนข้อมูล',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = $('<form>', {
                        method: 'POST',
                        action: 'manage_borrowings.php'
                    });
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'restore_borrowing',
                        value: '1'
                    }));
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'deleted_id',
                        value: id
                    }));
                    form.appendTo('body').submit();
                }
            });
        }

        // Initialize
        $(document).ready(function() {
            loadBorrowings();
            <?php if ($alertScript): ?>
                <?= $alertScript ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>


