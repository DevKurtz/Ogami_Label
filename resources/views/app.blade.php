<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-name" content="{{ auth()->user()->name }}">
    <meta name="desktop-mode" content="{{ filter_var(env('LOCAL_DESKTOP', false), FILTER_VALIDATE_BOOL) ? 'true' : 'false' }}">
    @php($desktopInstallerUrl = config('services.desktop.installer_url') ?: (file_exists(public_path('downloads/Ogami-Label-Studio-Setup-1.0.0.exe')) ? url('/downloads/Ogami-Label-Studio-Setup-1.0.0.exe') : ''))
    <meta name="desktop-installer-url" content="{{ $desktopInstallerUrl }}">
    <title>Ogami Label Studio</title>
    <link rel="icon" type="image/svg+xml" href="/ogami-mark.svg">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body><div id="app"></div></body>
</html>
