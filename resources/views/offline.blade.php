<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F6EFE8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1E1918" media="(prefers-color-scheme: dark)">
    <title>Bez připojení</title>
    {{-- Stránka se ukládá do zařízení, proto má styly přímo v sobě. --}}
    <style>
        :root { color-scheme: light dark; --bg: #F6EFE8; --text: #3B2E2C; --muted: #8E7A73; --primary: #B97D80; --on-primary: #FFFFFF; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1E1918; --text: #F3E9E4; --muted: #A8968F; --primary: #D6A7A8; --on-primary: #2A1F1E; } }
        body { margin: 0; min-height: 100dvh; display: flex; align-items: center; background: var(--bg); color: var(--text); font: 15px/1.45 -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 448px; margin: 0 auto; padding: 24px 18px; box-sizing: border-box; }
        h1 { font-size: 28px; font-weight: 600; line-height: 1.15; margin: 0 0 10px; }
        p { margin: 0 0 20px; color: var(--muted); }
        button { display: block; width: 100%; min-height: 48px; border: 0; border-radius: 14px; background: var(--primary); color: var(--on-primary); font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <h1>Jste bez připojení</h1>
        <p>Rezervace potřebují internet. Jakmile budete zase online, zkuste to znovu.</p>
        <button type="button" onclick="location.reload()">Zkusit znovu</button>
    </main>
</body>
</html>
