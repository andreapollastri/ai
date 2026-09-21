<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>TryGeorge</title>
        <meta name="description" content="Local NLI classifier. Typed questions about a situation, answered with numbers. Write in English.">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="app-body">
        <div id="app"></div>
        <script>
            window.__GEORGE__ = @json($george);
        </script>
    </body>
</html>
