<?php
session_start();

if (isset($_SESSION['role']) && isset($_SESSION['user'])) {
    header('Location: index.php');
    exit();
}

$divError = 0;

if (isset($_POST['username']) && isset($_POST['password'])) {
    require_once 'database.php';

    $result = Database::auth($_POST['username'], $_POST['password']);

    if ($result) {
        session_regenerate_id(true);

        $_SESSION['user'] = $result;

        // role basé sur is_admin (pris depuis la DB)
        $_SESSION['role'] = (!empty($result['is_admin']) && (int)$result['is_admin'] === 1) ? 'admin' : 'user';

        header('Location: index.php');
        exit();
    } else {
        $divError = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Enreg</title>
        <link href="assets/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets/sign-in.css" rel="stylesheet">
        <style>
            body { background-color: #f8f9fa; }
            .container {
                max-width: 400px; margin: 0 auto; padding: 20px; text-align: center;
                background-color: #fff; border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
            .form-signin-heading { margin-bottom: 20px; font-size: 24px; font-weight: bold; color: #333; }
            .form-floating { margin-bottom: 20px; }
            .form-floating label { color: #777; }
            .form-floating input {
                width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px;
            }
            .btn-primary {
                background-color: #007bff; border: none; width: 100%;
                padding: 12px; font-size: 18px; border-radius: 4px;
                cursor: pointer; color: #fff;
            }
            .btn-primary:hover { background-color: #0056b3; }
            .error-message { color: #ff0000; }
            .footer { margin-top: 20px; font-size: 14px; color: #777; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1 class="form-signin-heading">authentification</h1>
            <?php if ($divError) { ?>
                <div class="error-message">Erreur d'authentification!</div>
            <?php } ?>
            <form method="POST" action="login.php">
                <div class="form-floating">
                    <input type="text" class="form-control" id="username" name="username"
                        placeholder="name@example.com"
                        <?php echo (isset($_POST['username']) ? 'value="'.htmlspecialchars($_POST['username']).'"' : ""); ?>
                        required>
                    <label for="username">Email address / Username</label>
                </div>
                <div class="form-floating">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password">Password</label>
                </div>
                <button class="btn btn-primary" type="submit">Sign In</button>
            </form>
            <p class="footer">&copy; 2023</p>
        </div>
    </body>
</html>
