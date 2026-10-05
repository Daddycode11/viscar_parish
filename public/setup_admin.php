<?php
require_once __DIR__.'/../includes/auth.php';
if(!APP_LOCAL || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);exit('Initial setup is available on localhost only.');}
if($conn->query("SELECT COUNT(*) n FROM users WHERE role='admin'")->fetch_assoc()['n']>0){header('Location: login.php');exit;}
$_SESSION['setup_csrf']??=bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals($_SESSION['setup_csrf'],$_POST['_csrf']??'')){http_response_code(403);exit('Reload setup and try again.');}
// -----------------------------
// Handle form submission
// -----------------------------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm'];

    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($confirm)) {
        $errors[] = "All fields are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    if(strlen($password)<12)$errors[]='Use at least 12 characters for the administrator password.';
    // Insert admin if no errors
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (name,email,phone,password,role,status) VALUES (?, ?, ?, ?, 'admin','active')");
        $stmt->bind_param("ssss", $name, $email, $phone, $hashedPassword);

        if ($stmt->execute()) {
            // Update first_time_setup flag
            $conn->query("UPDATE settings SET first_time_setup = 1 WHERE id = 1");

            // Redirect to login after successful creation
            header("Location: login.php?setup=success");
            exit;
        } else {
            $errors[] = "Database error: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="UTF-8">
    <title>First-Time Admin Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .setup-container { background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: min(400px, 100%); box-sizing: border-box; }
        h2 { text-align: center; margin-bottom: 20px; color: #333; }
        input { width: 100%; padding: 10px; margin: 8px 0; border-radius: 5px; border: 1px solid #ccc; }
        button { width: 100%; padding: 12px; background: #43658b; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; }
        button:hover { background: #365675; }
        .error { color: red; margin-bottom: 10px; }
    </style>
<?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
<link rel="stylesheet" href="../assets/css/palette.css"></head>
<body>
<div class="setup-container">
    <h2>First-Time Admin Setup</h2>

    <?php
    if (!empty($errors)) {
        foreach ($errors as $err) {
            echo "<div class='error'>{$err}</div>";
        }
    }
    ?>

    <form method="POST"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($_SESSION['setup_csrf'])?>">
        <input type="text" name="name" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="text" name="phone" placeholder="Phone Number">
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="confirm" placeholder="Confirm Password" required>
        <button type="submit">Create Admin Account</button>
    </form>
</div>
</body>
</html>