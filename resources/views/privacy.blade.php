<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>TryGeorge · Privacy</title>
        @fonts
        @vite(['resources/css/app.css'])
        <script>
            (() => {
                const theme = localStorage.getItem('george.theme') ?? 'system';
                const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>
    </head>
    <body class="app-body">
        <main class="shell privacy">
            <header class="top">
                <a class="logo" href="/">TRY<span>GEORGE</span></a>
            </header>
            <h1>Privacy</h1>
            <p>TryGeorge scores your text on this PHP process, with an open-weight ONNX model. There is no call to TypeSafe, OpenAI, or Hugging Face at inference time (the one-off model download comes from Hugging Face).</p>
            <ul>
                <li>The situation and conditions stay in local SQLite and are pruned after 24 hours.</li>
                <li>No third-party analytics, no tracking cookies.</li>
                <li>You can wipe persistence by deleting <code>database/database.sqlite</code> or running <code>php artisan model:prune</code>.</li>
            </ul>
            <p>Write in English. The situation never leaves your machine unless you deploy this app to a host you control. That is the point.</p>
            <p><a href="/">← TryGeorge</a></p>
        </main>
    </body>
</html>
