<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';

require_once '../includes/db.php';
session_start();

// Check if an admin already exists
$check_admin = $conn->query("SELECT * FROM users WHERE role='admin'");
if($check_admin->num_rows > 0){
    // Redirect to login if admin exists
    header("Location: login.php");
    exit;
}

$message = '';

if(isset($_POST['create_admin'])){
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Basic validation
    if(empty($name) || empty($email) || empty($password) || empty($confirm_password)){
        $message = "All fields are required.";
    } elseif($password !== $confirm_password){
        $message = "Passwords do not match.";
    } else {
        // Hash password
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Insert first admin
        $stmt = $conn->prepare("INSERT INTO users (name,email,phone,password,role,status) VALUES (?,?,?,?,?,?)");
        $role = 'admin';
        $status = 'active';
        $stmt->bind_param("ssssss",$name,$email,$phone,$hash,$role,$status);

        if($stmt->execute()){
            $_SESSION['user'] = [
                'id' => $conn->insert_id,
                'name' => $name,
                'email' => $email,
                'role' => 'admin'
            ];
            // Redirect to admin dashboard
            header("Location: admin/dashboard.php");
            exit;
        } else {
            $message = "Failed to create admin. Email may already exist.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="UTF-8">
    <title>Setup Admin Account</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Poppins', sans-serif; background:#f8f9fa; display:flex; justify-content:center; align-items:center; height:100vh; }
        .container { background:#fff; padding:40px; border-radius:10px; box-shadow:0 2px 15px rgba(0,0,0,0.1); width:min(400px, 100%); box-sizing:border-box; }
        h2 { margin-bottom:25px; font-weight:600; text-align:center; }
        input { width:100%; padding:10px; margin:10px 0; border-radius:5px; border:1px solid #ccc; }
        button { width:100%; padding:12px; border:none; border-radius:50px; background:#0d6efd; color:#fff; font-weight:600; cursor:pointer; }
        button:hover { background:#0b5ed7; }
        .message { color:red; margin-bottom:15px; text-align:center; }
    </style>
<?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
</head>
<body>

<div class="container">
    <h2>Setup First Admin Account</h2>
    <?php if($message) echo "<p class='message'>$message</p>"; ?>
    <form method="POST">
        <input type="text" name="name" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="text" name="phone" placeholder="Phone" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        <button type="submit" name="create_admin">Create Admin Account</button>
    </form>
</div>

</body>
</html>