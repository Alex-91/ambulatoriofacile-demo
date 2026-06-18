<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($title ?? 'Sito in manutenzione') ?></title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f1ea;
            --card: #fffdf8;
            --text: #1f2933;
            --muted: #5b6570;
            --accent: #a24936;
            --border: rgba(31, 41, 51, 0.08);
            --shadow: 0 24px 60px rgba(31, 41, 51, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top left, rgba(162, 73, 54, 0.18), transparent 32%),
                radial-gradient(circle at bottom right, rgba(120, 146, 98, 0.18), transparent 28%),
                linear-gradient(135deg, #f7f3eb 0%, #efe7d8 100%);
            color: var(--text);
        }

        .panel {
            width: min(100%, 720px);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 48px 36px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 18px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(162, 73, 54, 0.12);
            color: var(--accent);
            font: 700 12px/1.2 Arial, Helvetica, sans-serif;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 14px;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1.05;
        }

        p {
            margin: 0 auto;
            max-width: 560px;
            font: 400 1.05rem/1.7 Arial, Helvetica, sans-serif;
            color: var(--muted);
        }

        .divider {
            width: 88px;
            height: 4px;
            margin: 28px auto;
            border-radius: 999px;
            background: linear-gradient(90deg, #a24936, #d47b58);
        }

        .note {
            margin-top: 22px;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <main class="panel">
        <div class="eyebrow">Maintenance mode</div>
        <h1><?= esc($title ?? 'Sito in manutenzione') ?></h1>
        <div class="divider"></div>
        <p><?= esc($message ?? 'Stiamo facendo un intervento tecnico. Riprova tra poco.') ?></p>
        <p class="note">Accesso temporaneamente non disponibile.</p>
    </main>
</body>
</html>
