<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-name" content="{{ auth()->user()->name }}">
    <title>Ogami Label Studio</title>
    <link rel="icon" type="image/svg+xml" href="/ogami-mark.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body><div id="app"></div></body>
</html>
