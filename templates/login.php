<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var array<string, string> $old */
/** @var string|null $error */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> Login</title>
    <style>
        :root {
            --bg: #f4efe6;
            --panel: #fffaf2;
            --ink: #1d1d1b;
            --muted: #6c665d;
            --accent: #0a7c66;
            --accent-dark: #075c4c;
            --border: #ddd2c0;
            --danger-bg: #fde9e7;
            --danger-border: #e7b8b2;
            --danger-text: #7f1d1d;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top left, rgba(10, 124, 102, 0.10), transparent 28%),
                linear-gradient(135deg, #f4efe6, #efe6d7);
            color: var(--ink);
            font-family: Georgia, "Times New Roman", serif;
        }

        .card {
            width: min(100%, 420px);
            margin: 24px;
            padding: 32px;
            background: rgba(255, 250, 242, 0.95);
            border: 1px solid var(--border);
            border-radius: 22px;
            box-shadow: 0 20px 60px rgba(52, 42, 28, 0.12);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 2rem;
            line-height: 1.1;
        }

        p {
            margin: 0 0 24px;
            color: var(--muted);
            font-family: "Segoe UI", sans-serif;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font: 600 0.95rem/1.2 "Segoe UI", sans-serif;
        }

        input {
            width: 100%;
            margin-bottom: 18px;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            font: 400 1rem/1.3 "Segoe UI", sans-serif;
        }

        button {
            width: 100%;
            padding: 14px 16px;
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            font: 700 1rem/1 "Segoe UI", sans-serif;
            cursor: pointer;
        }

        button:hover {
            background: var(--accent-dark);
        }

        .error {
            margin-bottom: 18px;
            padding: 12px 14px;
            border: 1px solid var(--danger-border);
            border-radius: 12px;
            background: var(--danger-bg);
            color: var(--danger-text);
            font: 500 0.95rem/1.4 "Segoe UI", sans-serif;
        }
    </style>
</head>
<body>
<main class="card">
    <h1><?= htmlspecialchars($appName) ?></h1>
    <p>Control Plane sign-in for operators and admins.</p>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="<?= htmlspecialchars($old['username'] ?? '') ?>" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Sign In</button>
    </form>
</main>
</body>
</html>
