<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - KBUS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <style>
        :root {
            --forest: #185A85;
            --ink: #102A43;
            --ink-soft: #627D98;
            --paper: #F2F7FB;
            --line: #D9E2EC;
            --white: #FFFFFF;
            --amber: #F39C12;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--paper);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--forest);
            color: var(--white);
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 99px;
            letter-spacing: .5px;
        }

        h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--ink);
        }

        p {
            font-size: 15px;
            color: var(--ink-soft);
            max-width: 380px;
            text-align: center;
            line-height: 1.6;
        }

        .logout-link {
            margin-top: 8px;
            font-size: 13px;
            color: var(--ink-soft);
            text-decoration: none;
            border: 1px solid var(--line);
            padding: 6px 16px;
            border-radius: 8px;
            transition: border-color .15s, color .15s;
        }

        .logout-link:hover {
            border-color: var(--forest);
            color: var(--forest);
        }
    </style>
</head>

<body>
    <span class="admin-badge">&#9679; Admin</span>
    <h1>Dashboard Admin</h1>
    <p>Panel admin sedang dalam pengembangan. Fitur manajemen produk, pengguna, dan laporan akan tersedia di sini.</p>
    <a href="../index.php" class="logout-link">&#8592; Kembali ke Login</a>
</body>

</html>
